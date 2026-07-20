<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Invoicing\Exceptions\InvoiceActionDenied;
use App\Billing\Invoicing\ValueObjects\ManualLine;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Seller\SellerCatalog;
use App\Billing\Support\WeightedAllocator;
use App\Billing\Tax\TaxContextFactory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\ValueObjects\Invoice as IssuedInvoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteLine;
use Cbox\Billing\Quote\ValueObjects\QuoteTotals;
use Cbox\Billing\Refund\Contracts\Refunder;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\ValueObjects\Refund;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Runs the invoice lifecycle operations (Wave 3) through the engine's money primitives.
 * The service owns the durable-model ↔ engine-value-object translation and the
 * server-side guards; the engine owns the money:
 *
 *  - {@see Refund()} projects the durable invoice into the engine's {@see IssuedInvoice}
 *    value object and hands a {@see RefundRequest} to the {@see Refunder}, which draws a
 *    credit-note number, posts the reversing ledger transaction, moves the money through
 *    the gateway, and fires {@see CreditNoteIssued} — the whole
 *    thing idempotent on the action id and capped at the amount charged.
 *  - {@see createManual()} prices operator lines through the {@see QuoteBuilder} and
 *    finalizes through the {@see Invoicer} (legal number + currency lock).
 *
 * Voiding and mark-paid are guarded status transitions on the durable row; every guard
 * refuses server-side rather than trusting the confirm dialog.
 */
readonly class InvoiceOperations implements RunsInvoiceOperations
{
    public function __construct(
        private ConnectionInterface $db,
        private Refunder $refunder,
        private QuoteBuilder $quotes,
        private Invoicer $invoicer,
        private SellerCatalog $sellers,
        private TaxContextFactory $taxContexts,
        private NotifiesCustomers $notifier,
    ) {}

    public function void(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw InvoiceActionDenied::alreadyVoided();
        }

        if (! $invoice->status->isVoidable()) {
            throw InvoiceActionDenied::notVoidable($invoice->status->value);
        }

        $invoice->forceFill(['status' => InvoiceStatus::Void])->save();
    }

    public function refund(Invoice $invoice, ?int $netMinor, RefundReason $reason, string $actionId): Refund
    {
        if (! $invoice->status->isRefundable()) {
            throw InvoiceActionDenied::notRefundable($invoice->status->value);
        }

        $engineInvoice = $this->toEngineInvoice($invoice);
        $at = Carbon::now()->toDateTimeImmutable();

        $request = $netMinor === null
            ? RefundRequest::full($actionId, $invoice->organization_id, $engineInvoice, $reason, $at, $invoice->gateway_reference)
            : RefundRequest::partial($actionId, $invoice->organization_id, $engineInvoice, Money::ofMinor($netMinor, $invoice->currency), $reason, $at, $invoice->gateway_reference);

        // The refunder's ledger post, refund-record save and CreditNoteIssued listener
        // (which writes the durable credit note) all commit together.
        return $this->db->transaction(fn (): Refund => $this->refunder->refund($request));
    }

    public function markPaid(Invoice $invoice, ?string $reference): void
    {
        if ($invoice->isPaid()) {
            return;
        }

        $invoice->markPaid($invoice->total(), $reference ?? 'manual:'.$invoice->number);

        // Queue the receipt for the now-settled invoice (the same mail a webhook settle
        // sends), so an offline settlement is confirmed to the customer.
        $this->notifier->paymentReceipt($invoice->refresh());
    }

    public function resend(Invoice $invoice): void
    {
        $this->notifier->invoiceResent($invoice->loadMissing('organization'));
    }

    public function createManual(Organization $organization, array $lines): Invoice
    {
        $inputs = $this->lineInputs($organization, $lines);

        if ($inputs === []) {
            throw InvoiceActionDenied::noLines();
        }

        $seller = $this->sellers->default();
        $quote = $this->quotes->build($inputs, $this->taxContexts->forOrganization($organization));

        if (! $quote->isTaxResolved()) {
            throw InvoiceActionDenied::taxPending($quote->taxResolution->reason ?? 'unresolved jurisdiction');
        }

        return $this->db->transaction(function () use ($organization, $seller, $quote): Invoice {
            $issued = $this->invoicer->issue($quote, $seller, $organization->id, Carbon::now()->toDateTimeImmutable());

            return $this->persist($organization->id, $seller->id, $issued);
        });
    }

    /**
     * Build the priced line inputs, dropping any non-positive/blank line. Each carries a
     * per-unit NET amount in the account's invoice currency.
     *
     * @param  list<ManualLine>  $lines
     * @return list<LineInput>
     */
    private function lineInputs(Organization $organization, array $lines): array
    {
        $currency = $this->currencyFor($organization);
        $inputs = [];

        foreach ($lines as $line) {
            $description = trim($line->description);

            if ($description === '' || $line->quantity < 1 || $line->unitMinor <= 0) {
                continue;
            }

            $inputs[] = new LineInput(
                description: $description,
                quantity: $line->quantity,
                unitAmount: Money::ofMinor($line->unitMinor, $currency),
            );
        }

        return $inputs;
    }

    /** The account's locked billing currency, or the seller's default when not yet pinned. */
    private function currencyFor(Organization $organization): string
    {
        $currency = $organization->billing_currency;

        return is_string($currency) && $currency !== '' ? $currency : $this->sellers->default()->defaultCurrency;
    }

    /** Persist an issued engine invoice + its taxed lines as app rows. */
    private function persist(string $organizationId, string $sellerId, IssuedInvoice $issued): Invoice
    {
        $totals = $issued->totals;

        $invoice = Invoice::query()->create([
            'organization_id' => $organizationId,
            'seller' => $sellerId,
            'number' => $issued->number,
            'currency' => $issued->currency,
            'subtotal_minor' => $totals->net->minor(),
            'tax_minor' => $totals->tax->minor(),
            'total_minor' => $totals->gross->minor(),
            'status' => InvoiceStatus::Open,
            'issued_at' => Carbon::instance($issued->issuedAt),
            'due_at' => Carbon::instance($issued->issuedAt)->addDays(14),
        ]);

        foreach ($issued->lines as $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_minor' => WeightedAllocator::unitMinor($line->net->minor(), $line->quantity),
                'amount_minor' => $line->gross->minor(),
            ]);
        }

        return $invoice;
    }

    /**
     * Project the durable invoice into the engine's {@see IssuedInvoice} value object the
     * refunder reverses. The header net/tax/gross are exact; each stored line's net and tax are
     * reconstructed proportional to its gross via the shared largest-remainder
     * {@see WeightedAllocator}, so the lines reconcile exactly to the header (the leftover units
     * go to the largest fractional parts, not the last line).
     */
    private function toEngineInvoice(Invoice $invoice): IssuedInvoice
    {
        $invoice->loadMissing(['lines', 'organization']);
        $organization = $invoice->organization
            ?? throw new RuntimeException(sprintf('Invoice [%d] has no organization.', $invoice->id));

        $currency = $invoice->currency;
        $lines = $this->reconstructedLines($invoice);

        $totals = new QuoteTotals(
            net: Money::ofMinor($invoice->subtotal_minor, $currency),
            tax: Money::ofMinor($invoice->tax_minor, $currency),
            gross: Money::ofMinor($invoice->total_minor, $currency),
            credit: Money::zero($currency),
            dueNow: Money::ofMinor($invoice->total_minor, $currency),
        );

        return new IssuedInvoice(
            number: $invoice->number,
            seller: $this->sellers->entity($invoice->seller),
            place: $this->taxContexts->forOrganization($organization)->place,
            currency: $currency,
            lines: $lines,
            totals: $totals,
            issuedAt: ($invoice->issued_at ?? Carbon::now())->toDateTimeImmutable(),
        );
    }

    /**
     * The invoice's lines as engine {@see QuoteLine}s, distributing the header net/tax
     * across them proportional to each line's gross so the parts reconcile to the whole.
     *
     * @return list<QuoteLine>
     */
    private function reconstructedLines(Invoice $invoice): array
    {
        $currency = $invoice->currency;
        $rows = array_values($invoice->lines->all());
        $grossTotal = $invoice->total_minor;

        if ($rows === [] || $grossTotal <= 0) {
            // A degenerate/zero invoice: a single aggregate line equal to the header.
            return [new QuoteLine(
                description: 'Invoice '.$invoice->number,
                quantity: 1,
                net: Money::ofMinor($invoice->subtotal_minor, $currency),
                tax: Money::ofMinor($invoice->tax_minor, $currency),
                gross: Money::ofMinor($invoice->total_minor, $currency),
                treatment: null,
                taxRatePercentage: null,
                taxNote: '',
            )];
        }

        // Split the header net + tax across the lines by each line's gross, through the one shared
        // allocator (largest-remainder) so both sums reconcile to the header exactly.
        $weights = array_map(static fn (InvoiceLine $row): int => (int) $row->amount_minor, $rows);
        $nets = WeightedAllocator::allocate($invoice->subtotal_minor, $weights);
        $taxes = WeightedAllocator::allocate($invoice->tax_minor, $weights);

        $lines = [];

        foreach ($rows as $slot => $row) {
            $net = $nets[$slot];
            $tax = $taxes[$slot];

            $lines[] = new QuoteLine(
                description: (string) $row->description,
                quantity: (int) $row->quantity,
                net: Money::ofMinor($net, $currency),
                tax: Money::ofMinor($tax, $currency),
                gross: Money::ofMinor($net + $tax, $currency),
                treatment: null,
                taxRatePercentage: null,
                taxNote: '',
            );
        }

        return $lines;
    }
}
