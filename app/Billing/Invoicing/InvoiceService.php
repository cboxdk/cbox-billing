<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Coupons\Contracts\DiscountsAmounts;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Seller\SellerCatalog;
use App\Billing\Support\WeightedAllocator;
use App\Billing\Tax\Exemptions\ExemptionContext;
use App\Billing\Tax\TaxContextFactory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use App\Models\TaxExemptionCertificate;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\ValueObjects\Invoice as IssuedInvoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Generates a period invoice for a subscription. The flow is the engine's convergence
 * point made concrete:
 *
 *  1. Build the line inputs from the catalog (the plan's recurring price).
 *  2. Run them through the {@see QuoteBuilder}, which taxes each line for the org's place
 *     of supply against the seller's registrations — an org with no address yields a
 *     tax-pending quote (net prices, honest reason).
 *  3. Hand the confirmed quote to the {@see Invoicer}, which draws the seller's next legal
 *     number and stamps the account's billing currency on first finalize.
 *  4. Persist the issued invoice + its taxed lines as app rows.
 *
 * Metered overage is deliberately NOT priced into a line here: usage is captured in the
 * event log and converged to the ledger by reconciliation; turning credit-denominated
 * usage into money is a pricing decision this host does not fabricate.
 */
readonly class InvoiceService implements GeneratesInvoices
{
    public function __construct(
        private ConnectionInterface $db,
        private QuoteBuilder $quotes,
        private Invoicer $invoicer,
        private SellerCatalog $sellers,
        private TaxContextFactory $taxContexts,
        private ResolvesAccountCurrency $currencies,
        private NotifiesCustomers $notifier,
        private DiscountsAmounts $coupons,
        private ExemptionContext $exemptions,
        private BillingClock $clock,
    ) {}

    public function quoteFor(Subscription $subscription): Quote
    {
        $organization = $this->organizationOf($subscription);
        $currency = $this->currencies->for($organization);

        return $this->quotes->build($this->lines($subscription, $currency), $this->taxContexts->forOrganization($organization));
    }

    public function generate(Subscription $subscription): Invoice
    {
        $organization = $this->organizationOf($subscription);
        $seller = $this->sellers->default();

        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;

        // Idempotent per (subscription, period) [H4]: issuance is at-least-once (a job retry,
        // a concurrent renewal), so a period already invoiced returns the existing invoice
        // rather than minting a second legal number and double-charging.
        $existing = $this->existingPeriodInvoice($subscription, $periodStart, $periodEnd);

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $quote = $this->quoteFor($subscription);

        if (! $quote->isTaxResolved()) {
            throw new RuntimeException(sprintf(
                'Cannot invoice organization [%s]: tax is pending (%s). Set a billing address first.',
                $organization->id,
                $quote->taxResolution->reason ?? 'unresolved jurisdiction',
            ));
        }

        // The certificate (if any) that exempted this quote — captured now, before the issue
        // step (which never re-taxes), so it can be stamped on the invoice as the audit trail.
        $exemption = $this->exemptions->appliedCertificate();

        try {
            $invoice = $this->db->transaction(function () use ($subscription, $organization, $seller, $quote, $periodStart, $periodEnd, $exemption): Invoice {
                $issued = $this->invoicer->issue($quote, $seller, $organization->id, $this->issuedAt());
                $invoice = $this->persist($subscription, $seller->id, $issued, $periodStart, $periodEnd, $exemption);

                // Honor the coupon's duration: this period consumed one discounted invoice.
                // Inside the same transaction as the issue, so a unique-guard rollback (a lost
                // concurrent race) never leaves a phantom decrement.
                $this->consumeCouponPeriod($subscription);

                return $invoice;
            });
        } catch (QueryException $e) {
            // Lost the race to a concurrent issuance: the (subscription, period) unique guard
            // rejected the duplicate. Return whatever the winner committed.
            $winner = $this->existingPeriodInvoice($subscription, $periodStart, $periodEnd);

            if ($winner instanceof Invoice) {
                return $winner;
            }

            throw $e;
        }

        // Notify the billing contact once the invoice is durably finalized (outside the
        // transaction, so a queued send never rides an uncommitted invoice).
        $this->notifier->invoiceIssued($invoice, $subscription);

        return $invoice;
    }

    /**
     * Issue an ad-hoc invoice for a mid-cycle amount due now (a plan-change / seat / add-on
     * proration), stamped to the subscription but with no period key so it is exempt from the
     * period-idempotency guard (a subscription can be charged several prorations in one
     * cycle). The amount is taxed through the same {@see QuoteBuilder} the period invoice
     * uses, so the charged gross equals the previewed due-now by construction (H6).
     */
    public function grossDueNow(Subscription $subscription, Money $dueNow): Money
    {
        // The same quote the charge builds — but read only for its gross, never issued. Tax
        // is per-line, so the description does not affect the total; a fixed label keeps this
        // pure. A tax-pending org has no rate, so gross == net.
        return $this->dueNowQuote($subscription, $dueNow, 'Due now')->totals->gross;
    }

    public function issueDueNow(Subscription $subscription, Money $dueNow, string $description): Invoice
    {
        $organization = $this->organizationOf($subscription);
        $seller = $this->sellers->default();

        $quote = $this->dueNowQuote($subscription, $dueNow, $description);

        if (! $quote->isTaxResolved()) {
            throw new RuntimeException(sprintf(
                'Cannot charge organization [%s]: tax is pending (%s). Set a billing address first.',
                $organization->id,
                $quote->taxResolution->reason ?? 'unresolved jurisdiction',
            ));
        }

        $exemption = $this->exemptions->appliedCertificate();

        $invoice = $this->db->transaction(function () use ($subscription, $organization, $seller, $quote, $exemption): Invoice {
            $issued = $this->invoicer->issue($quote, $seller, $organization->id, $this->issuedAt());

            return $this->persist($subscription, $seller->id, $issued, null, null, $exemption);
        });

        $this->notifier->invoiceIssued($invoice, $subscription);

        return $invoice;
    }

    /**
     * The engine quote for a mid-cycle amount due now — one line at `$dueNow`, taxed for the
     * org's place of supply. Shared by {@see issueDueNow()} (which issues it) and
     * {@see grossDueNow()} (which reads only its gross), so a preview and its charge are the
     * same computation by construction.
     */
    private function dueNowQuote(Subscription $subscription, Money $dueNow, string $description): Quote
    {
        return $this->quotes->build(
            [new LineInput($description, 1, $dueNow)],
            $this->taxContexts->forOrganization($this->organizationOf($subscription)),
        );
    }

    /**
     * The already-issued period invoice for this subscription's `[start, end]`, or null when
     * none exists (or the period is not fully bounded, in which case there is no key to
     * dedup on). Proration invoices carry a null period and are never matched here.
     */
    private function existingPeriodInvoice(Subscription $subscription, ?Carbon $periodStart, ?Carbon $periodEnd): ?Invoice
    {
        if ($periodStart === null || $periodEnd === null) {
            return null;
        }

        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();
    }

    private function organizationOf(Subscription $subscription): Organization
    {
        return $subscription->organization
            ?? throw new RuntimeException(sprintf('Subscription [%d] has no organization to invoice.', $subscription->id));
    }

    /**
     * The catalog line inputs for the period: the plan's recurring subscription fee for
     * the subscription's seat count, priced in the account's billing currency by the
     * engine's pricing-model calculator ({@see Plan::amountFor()} → {@see Price::amountFor()}).
     * So a per-unit plan bills unit × seats and a tiered plan bills from its tier set —
     * the same seat-aware figure MRR and the change preview compute, never the raw base.
     *
     * The line is quantity 1 at the full computed amount (the shape the engine's own
     * plan-change quote uses): a tiered charge has no single "unit", so the honest
     * representation is the computed period total, with the seat count in the description.
     *
     * @return list<LineInput>
     */
    private function lines(Subscription $subscription, string $currency): array
    {
        $plan = $subscription->plan ?? throw new RuntimeException('Subscription has no plan to invoice.');
        $net = $plan->amountFor($currency, $subscription->seats);

        $lines = [
            new LineInput(
                description: sprintf('%s — subscription, %d seat(s) (%s)', $plan->name, max(1, $subscription->seats), $this->periodLabel($subscription)),
                quantity: 1,
                unitAmount: $net,
            ),
        ];

        // A bound coupon becomes a real, engine-taxed DISCOUNT LINE (a negated net computed
        // by the engine {@see \Cbox\Billing\Pricing\CouponApplier}, never a hand-subtracted
        // total): the quote builder taxes it at the same rate as the plan line, so the
        // invoice totals reflect the discounted net + its tax exactly — preview == charge.
        $discountLine = $this->couponLine($subscription, $net);

        if ($discountLine !== null) {
            $lines[] = $discountLine;
        }

        return $lines;
    }

    /**
     * The discount line for the subscription's bound coupon over `$net`, or null when there
     * is no binding, it no longer applies (its periods are spent), or it reduces nothing.
     * Pure — issuance decrements the binding separately ({@see consumeCouponPeriod()}).
     */
    private function couponLine(Subscription $subscription, Money $net): ?LineInput
    {
        $binding = $subscription->coupon;

        if (! $binding instanceof SubscriptionCoupon) {
            return null;
        }

        $discount = $this->coupons->forBinding($binding, $net, $this->issuedAt());

        if ($discount === null) {
            return null;
        }

        return new LineInput(
            description: sprintf('Discount — %s', $binding->label()),
            quantity: 1,
            unitAmount: $discount->amount->negated(),
        );
    }

    /**
     * Decrement the subscription's coupon binding by one issued period invoice. A `forever`
     * binding (null remaining) is untouched; a `once` / `repeating` binding counts down and
     * stops discounting at zero. Called only on genuine issuance (a re-run that returns the
     * existing period invoice never reaches here), so the duration is honored exactly once
     * per period.
     */
    private function consumeCouponPeriod(Subscription $subscription): void
    {
        $binding = $subscription->coupon;

        if (! $binding instanceof SubscriptionCoupon) {
            return;
        }

        if (! $binding->appliesNow() || $binding->remaining_periods === null) {
            return;
        }

        $binding->forceFill(['remaining_periods' => max(0, $binding->remaining_periods - 1)])->save();
    }

    private function persist(Subscription $subscription, string $sellerId, IssuedInvoice $issued, ?Carbon $periodStart, ?Carbon $periodEnd, ?TaxExemptionCertificate $exemption): Invoice
    {
        $totals = $issued->totals;

        $invoice = Invoice::query()->create([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'seller' => $sellerId,
            'number' => $issued->number,
            'currency' => $issued->currency,
            'subtotal_minor' => $totals->net->minor(),
            'tax_minor' => $totals->tax->minor(),
            'total_minor' => $totals->gross->minor(),
            'status' => InvoiceStatus::Open,
            'issued_at' => Carbon::instance($issued->issuedAt),
            'due_at' => Carbon::instance($issued->issuedAt)->addDays(14),
            // The exemption audit trail: which certificate zero-rated this invoice, if any.
            'exemption_certificate_id' => $exemption?->id,
            'exemption_reason' => $exemption?->exemptionReason(),
        ]);

        foreach ($issued->lines as $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_minor' => WeightedAllocator::unitMinor($line->net->minor(), $line->quantity),
                'net_minor' => $line->net->minor(),
                'amount_minor' => $line->gross->minor(),
                // The engine's per-line verdict, persisted so an exempt line is legible on the
                // invoice (treatment `exempt`, note = the certificate reason).
                'tax_treatment' => $line->treatment?->value,
                'tax_note' => $line->taxNote,
                'tax_rate' => $line->taxRatePercentage,
            ]);
        }

        return $invoice;
    }

    private function periodLabel(Subscription $subscription): string
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null) {
            return 'current period';
        }

        return sprintf('%s – %s', $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    /**
     * The instant an invoice is issued — read from the {@see BillingClock} so a test-clock pass
     * (virtual time) dates the invoice, its 14-day due date, and the coupon-window check at the
     * advanced instant, not real wall-clock now.
     */
    private function issuedAt(): DateTimeImmutable
    {
        return $this->clock->now()->toDateTimeImmutable();
    }
}
