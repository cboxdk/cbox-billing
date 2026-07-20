<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Approvals\Enums\ApprovalStatus;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Models\ApprovalRequest;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The general approval-workflow engine, exercised end-to-end through the refund path (money out
 * → credit note). Asserts the exact maker-checker semantics on real minor-unit vectors: below
 * threshold executes now; above threshold is HELD (no credit note); a DIFFERENT operator's
 * approval executes it exactly once; the maker cannot self-approve; reject executes nothing; and
 * a re-approve is an idempotent no-op.
 *
 * The invoiced org bills the Team plan @ 20 seats in DKK: 99 000 net, 25% DK VAT → 24 750 tax,
 * 123 750 gross (the same vector the direct refund test uses).
 */
class ApprovalRefundConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $maker = ['auth.user' => [
        'sub' => 'demo|maker', 'name' => 'Maker Operator', 'email' => 'maker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    /** @var array<string, mixed> */
    private array $checker = ['auth.user' => [
        'sub' => 'demo|checker', 'name' => 'Checker Operator', 'email' => 'checker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-07-18 10:00:00');
    }

    /** Enable the refund gate with the given amount threshold (minor units). */
    private function requireApprovalAbove(int $thresholdMinor, int $required = 1): void
    {
        config()->set('billing.approvals.actions', [
            'invoice.refund' => ['enabled' => true, 'threshold_minor' => $thresholdMinor, 'required' => $required],
        ]);
    }

    private function invoicedOrg(string $org = 'org_inv'): Invoice
    {
        Organization::query()->create(['id' => $org, 'name' => ucfirst($org), 'billing_email' => $org.'@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $subscription = Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $team->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 20,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);

        return app(GeneratesInvoices::class)->generate($subscription->refresh());
    }

    public function test_refund_below_threshold_executes_immediately_as_today(): void
    {
        // Threshold well above the 123 750 gross — the refund runs directly, no approval held.
        $this->requireApprovalAbove(500_000_00);
        $invoice = $this->invoicedOrg();

        $this->withSession($this->maker)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'service_issue', 'idempotency_key' => 'k-below',
        ])->assertRedirect('/invoices/'.$invoice->id)->assertSessionHas('status');

        // The credit note is issued NOW, and nothing was held.
        $this->assertSame(1, CreditNote::query()->where('invoice_number', $invoice->number)->count());
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_refund_above_threshold_is_held_and_issues_no_credit_note(): void
    {
        $this->requireApprovalAbove(100_00);
        $invoice = $this->invoicedOrg();

        $this->withSession($this->maker)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'service_issue', 'idempotency_key' => 'k-held',
        ])->assertRedirect('/invoices/'.$invoice->id)->assertSessionHas('status');

        // NO credit note yet — the refund is captured as a pending request with the exact amount.
        $this->assertSame(0, CreditNote::query()->where('invoice_number', $invoice->number)->count());

        $request = ApprovalRequest::query()->firstOrFail();
        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame('invoice.refund', $request->action_type->value);
        $this->assertSame(123_750, $request->amount_minor);
        $this->assertSame('demo|maker', $request->requested_by_sub);

        // The creation is on the tamper-evident trail; no refund event yet.
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'approval.requested']);
        $this->assertDatabaseMissing('operator_audit_events', ['action' => 'invoice.refunded']);
    }

    public function test_a_different_operator_approves_and_executes_the_refund_exactly_once(): void
    {
        $this->requireApprovalAbove(100_00);
        $invoice = $this->invoicedOrg();

        $this->withSession($this->maker)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'service_issue', 'idempotency_key' => 'k-exec',
        ]);
        $request = ApprovalRequest::query()->firstOrFail();

        // A DIFFERENT operator approves — the held refund now runs.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve', ['note' => 'Verified with finance'])
            ->assertRedirect('/approvals')->assertSessionHas('status');

        $note = CreditNote::query()->where('invoice_number', $invoice->number)->firstOrFail();
        $this->assertSame(99_000, $note->net_minor);
        $this->assertSame(24_750, $note->tax_minor);
        $this->assertSame(123_750, $note->gross_minor);
        $this->assertSame('service_issue', $note->reason);
        $this->assertSame('CBOX-DK-CN-2026-00001', $note->number);

        $request->refresh();
        $this->assertSame(ApprovalStatus::Executed, $request->status);
        $this->assertSame('demo|checker', $request->approved_by_sub);
        $this->assertNotNull($request->executed_at);
        $this->assertSame($note->number, $request->result['credit_note'] ?? null);

        // Both the money effect and the approval consummation are on the trail.
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'invoice.refunded']);
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'approval.executed']);

        // Idempotent: re-approving the executed request issues NO second credit note.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')->assertRedirect();
        $this->assertSame(1, CreditNote::query()->where('invoice_number', $invoice->number)->count());
    }

    public function test_the_maker_cannot_approve_their_own_request(): void
    {
        $this->requireApprovalAbove(100_00);
        $invoice = $this->invoicedOrg();

        $this->withSession($this->maker)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'service_issue', 'idempotency_key' => 'k-self',
        ]);
        $request = ApprovalRequest::query()->firstOrFail();

        // The two-person rule: the maker approving their own request is refused.
        $this->withSession($this->maker)->post('/approvals/'.$request->id.'/approve')
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(ApprovalStatus::Pending, $request->refresh()->status);
        $this->assertSame(0, CreditNote::query()->where('invoice_number', $invoice->number)->count());
    }

    public function test_reject_executes_nothing(): void
    {
        $this->requireApprovalAbove(100_00);
        $invoice = $this->invoicedOrg();

        $this->withSession($this->maker)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full', 'reason' => 'service_issue', 'idempotency_key' => 'k-reject',
        ]);
        $request = ApprovalRequest::query()->firstOrFail();

        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/reject', ['note' => 'Not warranted'])
            ->assertRedirect('/approvals')->assertSessionHas('status');

        $request->refresh();
        $this->assertSame(ApprovalStatus::Rejected, $request->status);
        $this->assertSame(0, CreditNote::query()->where('invoice_number', $invoice->number)->count());
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'approval.rejected']);
        $this->assertDatabaseMissing('operator_audit_events', ['action' => 'invoice.refunded']);
    }

    public function test_partial_refund_above_threshold_holds_the_exact_net_and_executes_it(): void
    {
        $this->requireApprovalAbove(100_00);
        $invoice = $this->invoicedOrg();

        // Partial net 50 000; tax reversed proportionally: 24 750 * 50 000 / 99 000 = 12 500.
        $this->withSession($this->maker)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'partial', 'amount_minor' => 50_000, 'reason' => 'goodwill', 'idempotency_key' => 'k-part',
        ]);
        $request = ApprovalRequest::query()->firstOrFail();
        $this->assertSame(50_000, $request->amount_minor);
        $this->assertSame(0, CreditNote::query()->where('invoice_number', $invoice->number)->count());

        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')->assertSessionHas('status');

        $note = CreditNote::query()->where('invoice_number', $invoice->number)->firstOrFail();
        $this->assertSame(50_000, $note->net_minor);
        $this->assertSame(12_500, $note->tax_minor);
        $this->assertSame(62_500, $note->gross_minor);
    }
}
