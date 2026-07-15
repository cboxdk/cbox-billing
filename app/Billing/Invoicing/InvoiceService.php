<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Seller\SellerCatalog;
use App\Billing\Tax\TaxContextFactory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\ValueObjects\Invoice as IssuedInvoice;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
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
    ) {}

    public function quoteFor(Subscription $subscription): Quote
    {
        $organization = $this->organizationOf($subscription);

        return $this->quotes->build($this->lines($subscription), $this->taxContexts->forOrganization($organization));
    }

    public function generate(Subscription $subscription): Invoice
    {
        $organization = $this->organizationOf($subscription);
        $seller = $this->sellers->default();
        $quote = $this->quoteFor($subscription);

        if (! $quote->isTaxResolved()) {
            throw new RuntimeException(sprintf(
                'Cannot invoice organization [%s]: tax is pending (%s). Set a billing address first.',
                $organization->id,
                $quote->taxResolution->reason ?? 'unresolved jurisdiction',
            ));
        }

        return $this->db->transaction(function () use ($subscription, $organization, $seller, $quote): Invoice {
            $issued = $this->invoicer->issue($quote, $seller, $organization->id, $this->issuedAt());

            return $this->persist($subscription, $seller->id, $issued);
        });
    }

    private function organizationOf(Subscription $subscription): Organization
    {
        return $subscription->organization
            ?? throw new RuntimeException(sprintf('Subscription [%d] has no organization to invoice.', $subscription->id));
    }

    /**
     * The catalog line inputs for the period: the plan's recurring subscription fee.
     *
     * @return list<LineInput>
     */
    private function lines(Subscription $subscription): array
    {
        $plan = $subscription->plan ?? throw new RuntimeException('Subscription has no plan to invoice.');

        return [
            new LineInput(
                description: sprintf('%s — subscription (%s)', $plan->name, $this->periodLabel($subscription)),
                quantity: 1,
                unitAmount: $plan->price(),
            ),
        ];
    }

    private function persist(Subscription $subscription, string $sellerId, IssuedInvoice $issued): Invoice
    {
        $totals = $issued->totals;

        $invoice = Invoice::query()->create([
            'organization_id' => $subscription->organization_id,
            'seller' => $sellerId,
            'number' => $issued->number,
            'currency' => $issued->currency,
            'subtotal_minor' => $totals->net->minor(),
            'tax_minor' => $totals->tax->minor(),
            'total_minor' => $totals->gross->minor(),
            'status' => 'open',
            'issued_at' => Carbon::instance($issued->issuedAt),
            'due_at' => Carbon::instance($issued->issuedAt)->addDays(14),
        ]);

        foreach ($issued->lines as $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_minor' => $line->quantity > 0 ? intdiv($line->net->minor(), $line->quantity) : $line->net->minor(),
                'amount_minor' => $line->gross->minor(),
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

    private function issuedAt(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
