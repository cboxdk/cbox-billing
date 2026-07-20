<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Invoicing\Exceptions\InvoiceActionDenied;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Refund\Enums\RefundReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * InvoiceStatus enum (platform-review P1 #4): the raw `'open'/'paid'/'draft'/'void'/…`
 * literals that leaked across the invoicing domain are now one backed enum cast on the
 * {@see Invoice} model. This pins that (a) the cast round-trips, (b) the refundable/voidable
 * classification and the lifecycle guard behave exactly as the old string rule did, and (c)
 * no raw status-logic literal survives in the touched domain files — so the vocabulary
 * cannot drift back apart.
 */
class InvoiceStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWith(InvoiceStatus $status): Invoice
    {
        Organization::query()->firstOrCreate(
            ['id' => 'org_enum'],
            ['name' => 'Org Enum', 'billing_email' => 'enum@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK'],
        );

        return Invoice::query()->create([
            'organization_id' => 'org_enum',
            'seller' => 'cbox-dk',
            'number' => 'INV-'.$status->value,
            'currency' => 'DKK',
            'subtotal_minor' => 10_000,
            'tax_minor' => 2_500,
            'total_minor' => 12_500,
            'status' => $status,
        ]);
    }

    public function test_status_is_cast_to_the_enum_and_round_trips_through_the_database(): void
    {
        $invoice = $this->invoiceWith(InvoiceStatus::Open);

        $reread = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame(InvoiceStatus::Open, $reread->status);

        // The column stores the backing string, so a raw query still reads 'open'.
        $this->assertSame('open', $reread->getRawOriginal('status'));
    }

    /**
     * @return iterable<string, array{InvoiceStatus, bool, bool}>
     */
    public static function classification(): iterable
    {
        // status, isRefundable, isVoidable
        yield 'draft' => [InvoiceStatus::Draft, false, false];
        yield 'open' => [InvoiceStatus::Open, true, true];
        yield 'paid' => [InvoiceStatus::Paid, true, false];
        yield 'void' => [InvoiceStatus::Void, false, false];
        yield 'uncollectible' => [InvoiceStatus::Uncollectible, true, true];
        yield 'refunded' => [InvoiceStatus::Refunded, false, false];
    }

    #[DataProvider('classification')]
    public function test_refundable_and_voidable_classification_matches_the_original_rule(InvoiceStatus $status, bool $refundable, bool $voidable): void
    {
        // The original hand-rolled rules were REFUNDABLE=[open,paid,uncollectible] and
        // VOIDABLE=[open,uncollectible] — the enum must classify identically.
        $this->assertSame($refundable, $status->isRefundable());
        $this->assertSame($voidable, $status->isVoidable());
    }

    public function test_the_refund_guard_still_refuses_a_non_refundable_invoice(): void
    {
        $operations = app(RunsInvoiceOperations::class);

        // Void is not refundable — the engine seam (the single guard) must refuse, exactly as
        // the old string guard did, even though RefundInvoiceAction no longer duplicates it.
        $void = $this->invoiceWith(InvoiceStatus::Void);
        $this->expectException(InvoiceActionDenied::class);
        $operations->refund($void, null, RefundReason::Requested, 'op-refund:enum-void');
    }

    public function test_the_void_guard_still_refuses_a_paid_invoice(): void
    {
        $operations = app(RunsInvoiceOperations::class);

        $paid = $this->invoiceWith(InvoiceStatus::Paid);
        $this->expectException(InvoiceActionDenied::class);
        $operations->void($paid);
    }

    public function test_no_raw_invoice_status_logic_literal_remains_in_the_touched_domain_files(): void
    {
        $files = [
            'app/Models/Invoice.php',
            'app/Billing/Invoicing/InvoiceOperations.php',
            'app/Billing/Reporting/InvoiceReport.php',
            'app/Billing/Reporting/RevenueMetrics.php',
            'app/Billing/Reporting/CustomerReport.php',
            'app/Billing/Support/SubscriptionStanding.php',
            'app/Billing/Hosted/PortalBillingHistory.php',
            'app/Billing/Import/BillingImporter.php',
            'app/Billing/Approvals/Actions/RefundInvoiceAction.php',
        ];

        // A status LOGIC literal is a raw value used in a comparison, an assignment to the
        // model's `status`, or a query constraint on it — the anti-patterns the enum replaces.
        // (Structural output-array keys and unrelated credit-note event statuses are not logic.)
        $values = 'draft|open|paid|void|uncollectible|refunded';
        $patterns = [
            "/->status\\s*(?:===|!==|==|!=)\\s*'(?:{$values})'/",     // raw comparison
            "/'status'\\s*=>\\s*'(?:{$values})'/",                    // raw model write
            "/where\\('status',\\s*'(?:{$values})'\\)/",             // raw query constraint
            '/in_array\\(\\$[a-zA-Z]+->status,\\s*\\[/',            // hand-rolled status set
        ];

        foreach ($files as $relative) {
            $source = file_get_contents(base_path($relative));
            $this->assertIsString($source);

            foreach ($patterns as $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $source),
                    "Raw invoice-status logic literal found in {$relative} (pattern {$pattern}) — use InvoiceStatus.",
                );
            }
        }
    }
}
