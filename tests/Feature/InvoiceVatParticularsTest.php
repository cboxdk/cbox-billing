<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\InvoiceDocument;
use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Invoicing\SellerDocumentIdentity;
use App\Billing\Invoicing\ValueObjects\TaxBand;
use App\Billing\Seller\SellerCatalog;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\SellerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice row already stored `tax_treatment`, `tax_note`, `tax_rate` and the supply period —
 * and the rendered PDF printed none of it. That made the one document the whole Invoicing module
 * exists to produce fail EU Directive 2006/112/EC:
 *
 *  - Art. 226(8)-(10): the rate applied and the VAT amount PER RATE. A mixed-rate invoice showed a
 *    single undifferentiated "Tax" line.
 *  - Art. 226(11a): the words "Reverse charge" wherever the customer is liable. A cross-border B2B
 *    invoice rendered as "Tax 0.00" with no explanation, so the buyer's auditor rejects it and the
 *    seller cannot evidence the treatment.
 *  - Art. 226(7): the date or period of supply where it differs from the issue date. Every
 *    subscription invoice covers one.
 *
 * The domain model was right the whole time; only the legal artifact was wrong.
 */
class InvoiceVatParticularsTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): void
    {
        SellerEntity::query()->firstOrCreate(['id' => 'seller_dk'], [
            'legal_name' => 'Seller DK ApS',
            'registration_number' => 'DK12345678',
            'establishment' => 'DK',
            'currency' => 'DKK',
            'invoice_prefix' => 'SDK',
        ]);
    }

    /**
     * @param  list<array{net: int, rate: ?string, treatment: ?string, note: ?string}>  $lines
     */
    private function invoiceWith(array $lines, int $taxMinor, string $number = 'SDK-2026-00001'): Invoice
    {
        $this->seller();

        Organization::query()->firstOrCreate(['id' => 'org_vat'], [
            'name' => 'Kunde GmbH',
            'billing_email' => 'kunde@example.test',
            'billing_country' => 'DE',
        ]);

        $net = array_sum(array_column($lines, 'net'));

        $invoice = Invoice::query()->create([
            'number' => $number,
            'organization_id' => 'org_vat',
            'seller' => 'seller_dk',
            'currency' => 'DKK',
            'status' => 'open',
            'subtotal_minor' => $net,
            'tax_minor' => $taxMinor,
            'total_minor' => $net + $taxMinor,
            'issued_at' => now(),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);

        foreach ($lines as $i => $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => 'Line '.($i + 1),
                'quantity' => 1,
                'unit_minor' => $line['net'],
                'net_minor' => $line['net'],
                'amount_minor' => $line['net'],
                'tax_rate' => $line['rate'],
                'tax_treatment' => $line['treatment'],
                'tax_note' => $line['note'],
            ]);
        }

        return $invoice->fresh();
    }

    public function test_a_reverse_charged_invoice_carries_the_mandatory_mention(): void
    {
        $invoice = $this->invoiceWith([
            ['net' => 100_000, 'rate' => null, 'treatment' => 'reverse_charge', 'note' => null],
        ], taxMinor: 0);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertStringContainsString('Reverse charge', $pdf);
        $this->assertStringContainsString('VAT treatment', $pdf);
    }

    /** A stored note from the tax engine is preferred over the generic wording. */
    public function test_a_stored_tax_note_is_used_verbatim(): void
    {
        $invoice = $this->invoiceWith([
            ['net' => 100_000, 'rate' => null, 'treatment' => 'reverse_charge', 'note' => 'Reverse charge, VAT Directive art. 196'],
        ], taxMinor: 0);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertStringContainsString('art. 196', $pdf);
    }

    public function test_a_mixed_rate_invoice_breaks_the_tax_down_per_rate(): void
    {
        // 100,00 at 25% (=25,00) and 100,00 at 12% (=12,00).
        $invoice = $this->invoiceWith([
            ['net' => 10_000, 'rate' => '25.00', 'treatment' => 'standard', 'note' => null],
            ['net' => 10_000, 'rate' => '12.00', 'treatment' => 'standard', 'note' => null],
        ], taxMinor: 3_700);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertStringContainsString('VAT 25%', $pdf);
        $this->assertStringContainsString('VAT 12%', $pdf);
    }

    public function test_the_supply_period_is_printed(): void
    {
        $invoice = $this->invoiceWith([
            ['net' => 10_000, 'rate' => '25.00', 'treatment' => 'standard', 'note' => null],
        ], taxMinor: 2_500);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertStringContainsString('Supply', $pdf);
        $this->assertStringContainsString(now()->startOfMonth()->format('Y-m-d'), $pdf);
    }

    /**
     * The per-band VAT must sum to the tax actually charged. Bands are apportioned from the
     * invoice total rather than recomputed per rate, precisely so integer rounding cannot make the
     * printed parts disagree with the total on the same page.
     */
    public function test_the_per_rate_breakdown_ties_out_to_the_invoice_total(): void
    {
        $invoice = $this->invoiceWith([
            ['net' => 3_333, 'rate' => '25.00', 'treatment' => 'standard', 'note' => null],
            ['net' => 3_333, 'rate' => '12.00', 'treatment' => 'standard', 'note' => null],
            ['net' => 3_334, 'rate' => '6.00', 'treatment' => 'standard', 'note' => null],
        ], taxMinor: 1_437);

        $document = new InvoiceDocument(
            $invoice->load(['organization', 'lines']),
            $invoice->organization,
            SellerDocumentIdentity::resolve(app(SellerCatalog::class), 'seller_dk'),
            false,
            null,
        );

        $method = new \ReflectionMethod($document, 'taxBreakdown');
        /** @var list<TaxBand> $bands */
        $bands = $method->invoke($document);

        $this->assertCount(3, $bands);
        $this->assertSame(
            1_437,
            array_sum(array_map(static fn ($b): int => $b->tax, $bands)),
            'The printed per-rate VAT amounts must sum to the tax charged on the invoice.',
        );
    }

    /** A standard-rated invoice gains no legend — the legend is for non-standard treatments only. */
    public function test_a_plain_domestic_invoice_has_no_vat_legend(): void
    {
        $invoice = $this->invoiceWith([
            ['net' => 10_000, 'rate' => '25.00', 'treatment' => 'standard', 'note' => null],
        ], taxMinor: 2_500);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertStringNotContainsString('VAT treatment', $pdf);
        $this->assertStringContainsString('VAT 25%', $pdf);
    }
}
