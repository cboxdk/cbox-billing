<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Payments\Exceptions\SettlementRejected;
use App\Billing\Seams\EloquentInvoicePaymentApplier;
use App\Models\Invoice;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money integrity (re-review remediation): a settled webhook only marks an invoice paid when its
 * amount AND currency match the invoice gross exactly. A settlement for the wrong amount (a signed
 * webhook claiming 1 minor against a 362.50 invoice) or the wrong currency is REFUSED, flagged in
 * the audit log for ops, and signalled with {@see SettlementRejected} so the ingest aborts before
 * the dedup guard — never marked paid. An exact match settles.
 */
class InvoiceSettlementValidationTest extends TestCase
{
    use RefreshDatabase;

    private function openInvoice(string $number = 'INV-SET-1'): Invoice
    {
        Organization::query()->create([
            'id' => 'org_set', 'name' => 'Set', 'billing_email' => 'set@example.test', 'billing_country' => 'DK',
        ]);

        return Invoice::query()->create([
            'organization_id' => 'org_set', 'seller' => 'seller_x', 'number' => $number, 'currency' => 'DKK',
            'subtotal_minor' => 29_000, 'tax_minor' => 7_250, 'total_minor' => 36_250,
            'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
        ]);
    }

    private function applier(): InvoicePaymentApplier
    {
        // The concrete invoice applier (not the checkout-activation decorator) — the seam that maps
        // a settled reference to its invoice.
        return app(EloquentInvoicePaymentApplier::class);
    }

    public function test_a_settlement_for_the_wrong_amount_is_refused_and_flagged(): void
    {
        $invoice = $this->openInvoice();

        try {
            $this->applier()->markPaid('INV-SET-1', Money::ofMinor(1, 'DKK'), PaymentResult::succeeded('gw_1'));
            $this->fail('A wrong-amount settlement must throw SettlementRejected.');
        } catch (SettlementRejected $e) {
            $this->assertSame('INV-SET-1', $e->reference);
        }

        $this->assertFalse($invoice->refresh()->isPaid());
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'invoice.settlement_rejected']);
    }

    public function test_a_settlement_in_the_wrong_currency_is_refused(): void
    {
        $invoice = $this->openInvoice('INV-SET-2');

        // Exact minor units but the wrong currency — still not a valid settlement.
        try {
            $this->applier()->markPaid('INV-SET-2', Money::ofMinor(36_250, 'EUR'), PaymentResult::succeeded('gw_2'));
            $this->fail('A wrong-currency settlement must throw SettlementRejected.');
        } catch (SettlementRejected) {
            // expected
        }

        $this->assertFalse($invoice->refresh()->isPaid());
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'invoice.settlement_rejected']);
    }

    public function test_an_exact_match_settles_the_invoice(): void
    {
        $invoice = $this->openInvoice('INV-SET-3');

        $this->applier()->markPaid('INV-SET-3', Money::ofMinor(36_250, 'DKK'), PaymentResult::succeeded('gw_3'));

        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame('gw_3', $invoice->gateway_reference);
        $this->assertSame(0, OperatorAuditEvent::query()->where('action', 'invoice.settlement_rejected')->count());
    }
}
