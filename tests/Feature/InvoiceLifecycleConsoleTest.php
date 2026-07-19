<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Mail\InvoiceIssuedMail;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The invoice lifecycle actions (Wave 3): void (guarded), refund → credit note through the
 * engine refunder (full + partial, exact minor units), manual/offline mark-paid
 * (idempotent), resend, and ad-hoc invoice creation with correct tax. Money moves through
 * the engine; every guard is server-side.
 */
class InvoiceLifecycleConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-07-18 10:00:00');
    }

    /** Team plan in DKK: 124 000 net, 25% DK VAT → 31 000 tax, 155 000 gross. */
    private function invoicedOrg(string $org = 'org_inv'): Invoice
    {
        Organization::query()->create(['id' => $org, 'name' => ucfirst($org), 'billing_email' => $org.'@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $subscription = Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $team->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);

        return app(GeneratesInvoices::class)->generate($subscription->refresh());
    }

    public function test_void_is_guarded_against_a_paid_invoice(): void
    {
        $invoice = $this->invoicedOrg();
        $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->withSession($this->session)
            ->post('/invoices/'.$invoice->id.'/void')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('paid', $invoice->fresh()?->status);
    }

    public function test_void_succeeds_on_an_open_invoice(): void
    {
        $invoice = $this->invoicedOrg();

        $this->withSession($this->session)
            ->post('/invoices/'.$invoice->id.'/void')
            ->assertRedirect('/invoices/'.$invoice->id)
            ->assertSessionHas('status');

        $this->assertSame('void', $invoice->fresh()?->status);
    }

    public function test_full_refund_issues_a_credit_note_of_the_exact_amount_and_reason(): void
    {
        $invoice = $this->invoicedOrg();

        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full',
            'reason' => 'service_issue',
            'idempotency_key' => 'k-full-1',
        ])->assertRedirect('/invoices/'.$invoice->id)->assertSessionHas('status');

        $note = CreditNote::query()->where('invoice_number', $invoice->number)->firstOrFail();
        $this->assertSame(124_000, $note->net_minor);
        $this->assertSame(31_000, $note->tax_minor);
        $this->assertSame(155_000, $note->gross_minor);
        $this->assertSame('service_issue', $note->reason);
        $this->assertSame('DKK', $note->currency);
        // Legal credit-note number off the seller's own CN sequence.
        $this->assertSame('CBOX-DK-CN-2026-00001', $note->number);

        // The engine refund record (idempotency + over-refund cap) and its ledger reversal.
        $this->assertDatabaseHas('refunds', ['refund_id' => 'op-refund:k-full-1', 'gross_minor' => 155_000, 'invoice_number' => $invoice->number]);
    }

    public function test_partial_refund_reverses_net_and_proportional_tax(): void
    {
        $invoice = $this->invoicedOrg();

        // Partial net 50 000; tax reversed in proportion: 31 000 * 50 000 / 124 000 = 12 500.
        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'partial',
            'amount_minor' => 50_000,
            'reason' => 'goodwill',
            'idempotency_key' => 'k-part-1',
        ])->assertRedirect()->assertSessionHas('status');

        $note = CreditNote::query()->where('invoice_number', $invoice->number)->firstOrFail();
        $this->assertSame(50_000, $note->net_minor);
        $this->assertSame(12_500, $note->tax_minor);
        $this->assertSame(62_500, $note->gross_minor);
    }

    public function test_over_refund_is_refused_by_the_cap(): void
    {
        $invoice = $this->invoicedOrg();

        // First a full refund, then a second refund attempt — the cumulative cap refuses it.
        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'requested', 'idempotency_key' => 'k-a',
        ])->assertSessionHas('status');

        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'partial', 'amount_minor' => 1000, 'reason' => 'requested', 'idempotency_key' => 'k-b',
        ]);

        // Only the first credit note exists — the second was capped out.
        $this->assertSame(1, CreditNote::query()->where('invoice_number', $invoice->number)->count());
    }

    public function test_mark_paid_records_a_payment_idempotently(): void
    {
        $invoice = $this->invoicedOrg();

        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/mark-paid', ['reference' => 'wire-123'])
            ->assertRedirect('/invoices/'.$invoice->id)->assertSessionHas('status');

        $invoice->refresh();
        $this->assertTrue($invoice->isPaid());
        $this->assertSame('wire-123', $invoice->gateway_reference);
        $paidAt = $invoice->paid_at;

        // Idempotent: a re-run does not rewrite the settlement.
        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/mark-paid', ['reference' => 'wire-999']);
        $invoice->refresh();
        $this->assertSame('wire-123', $invoice->gateway_reference);
        $this->assertEquals($paidAt, $invoice->paid_at);
    }

    public function test_resend_requeues_the_invoice_email(): void
    {
        Mail::fake();
        $invoice = $this->invoicedOrg();

        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/resend')
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertQueued(InvoiceIssuedMail::class, static fn (InvoiceIssuedMail $mail): bool => $mail->invoiceNumber === $invoice->number);
    }

    public function test_manual_invoice_issues_with_correct_tax_and_total(): void
    {
        Organization::query()->create(['id' => 'org_manual', 'name' => 'Manual', 'billing_email' => 'm@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        $this->withSession($this->session)->post('/invoices', [
            'organization_id' => 'org_manual',
            'lines' => [
                ['description' => 'Onboarding fee', 'quantity' => 1, 'amount_minor' => 100_000],
            ],
        ])->assertRedirect();

        $invoice = Invoice::query()->where('organization_id', 'org_manual')->firstOrFail();
        // 100 000 net at DK 25% VAT → 25 000 tax, 125 000 gross.
        $this->assertSame(100_000, $invoice->subtotal_minor);
        $this->assertSame(25_000, $invoice->tax_minor);
        $this->assertSame(125_000, $invoice->total_minor);
        $this->assertSame('open', $invoice->status);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'description' => 'Onboarding fee']);
    }

    public function test_credit_note_shows_on_invoice_and_in_the_list(): void
    {
        $invoice = $this->invoicedOrg();

        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'requested', 'idempotency_key' => 'k-show',
        ]);

        $note = CreditNote::query()->where('invoice_number', $invoice->number)->firstOrFail();

        $this->withSession($this->session)->get('/invoices/'.$invoice->id)
            ->assertOk()->assertSee($note->number);

        $this->withSession($this->session)->get('/credit-notes')
            ->assertOk()->assertSee($note->number);

        $this->withSession($this->session)->get('/credit-notes/'.$note->id)
            ->assertOk()->assertSee($invoice->number);
    }
}
