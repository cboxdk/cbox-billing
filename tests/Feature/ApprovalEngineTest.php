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
 * Cross-cutting guarantees of the approval engine that are not specific to one action: the
 * M-of-N quorum, one-decision-per-checker, the `approvals:decide` permission gate, livemode
 * scoping of the queue, the "always requires when enabled" path (customer.suspend), and the
 * checker queue render.
 */
class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $maker = ['auth.user' => [
        'sub' => 'demo|maker', 'name' => 'Maker', 'email' => 'maker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    /** @var array<string, mixed> */
    private array $checker = ['auth.user' => [
        'sub' => 'demo|checker', 'name' => 'Checker One', 'email' => 'checker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    /** @var array<string, mixed> */
    private array $checkerTwo = ['auth.user' => [
        'sub' => 'demo|checker2', 'name' => 'Checker Two', 'email' => 'checker2@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-07-18 10:00:00');
    }

    private function invoicedOrg(string $org = 'org_inv'): Invoice
    {
        Organization::query()->create(['id' => $org, 'name' => ucfirst($org), 'billing_email' => $org.'@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $subscription = Subscription::query()->create([
            'organization_id' => $org, 'plan_id' => $team->id, 'status' => SubscriptionStatus::Active, 'seats' => 20,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'), 'current_period_end' => Carbon::parse('2026-08-01', 'UTC'), 'cancel_at_period_end' => false,
        ]);

        return app(GeneratesInvoices::class)->generate($subscription->refresh());
    }

    /** Create a pending refund request directly (bypassing the console POST). */
    private function heldRefund(Invoice $invoice, int $required = 1): ApprovalRequest
    {
        return ApprovalRequest::query()->create([
            'action_type' => 'invoice.refund',
            'payload' => ['invoice_id' => $invoice->id, 'net_minor' => null, 'reason' => 'service_issue', 'idempotency_key' => 'k-'.$invoice->id],
            'requested_by_sub' => 'demo|maker', 'requested_by_name' => 'Maker', 'reason' => 'service_issue',
            'status' => ApprovalStatus::Pending->value, 'organization_id' => $invoice->organization_id,
            'amount_minor' => 123_750, 'currency' => 'DKK', 'target_type' => 'invoice', 'target_id' => (string) $invoice->id,
            'required_approvals' => $required,
        ]);
    }

    public function test_two_approvals_require_two_distinct_checkers(): void
    {
        $invoice = $this->invoicedOrg();
        $request = $this->heldRefund($invoice, required: 2);

        // First checker approves — quorum not yet met, nothing executes.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')->assertRedirect();
        $this->assertSame(ApprovalStatus::Pending, $request->refresh()->status);
        $this->assertSame(0, CreditNote::query()->count());

        // The same checker cannot approve again.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')->assertSessionHas('error');
        $this->assertSame(1, $request->refresh()->approvalCount());

        // A second DISTINCT checker meets the quorum → the refund runs.
        $this->withSession($this->checkerTwo)->post('/approvals/'.$request->id.'/approve')->assertSessionHas('status');
        $this->assertSame(ApprovalStatus::Executed, $request->refresh()->status);
        $this->assertSame(1, CreditNote::query()->where('invoice_number', $invoice->number)->count());
    }

    public function test_decide_requires_the_permission_when_rbac_is_enforced(): void
    {
        config()->set('billing.rbac.enforce', true);
        $invoice = $this->invoicedOrg();
        $request = $this->heldRefund($invoice);

        // A checker WITHOUT `approvals:decide` is refused by the permission gate.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')->assertForbidden();
        $this->assertSame(ApprovalStatus::Pending, $request->refresh()->status);

        // The same operator WITH the slug may decide.
        $permitted = $this->checker;
        $permitted['auth.user']['permissions'] = ['approvals:decide'];
        $this->withSession($permitted)->post('/approvals/'.$request->id.'/approve')->assertRedirect('/approvals');
        $this->assertSame(ApprovalStatus::Executed, $request->refresh()->status);
    }

    public function test_pending_queue_is_livemode_scoped(): void
    {
        $invoice = $this->invoicedOrg();
        $request = $this->heldRefund($invoice); // created in the live plane (default)
        $this->assertTrue((bool) $request->livemode);

        // In the LIVE plane the request is visible in the queue.
        $this->withSession($this->checker)->get('/approvals')->assertOk()->assertSee('#'.$request->id);

        // In the TEST plane the live request is filtered out (livemode scoping).
        $session = $this->checker;
        $session['console.test_mode'] = true;
        $this->withSession($session)->get('/approvals')->assertOk()->assertSee('Nothing to approve.');
    }

    public function test_suspend_always_requires_approval_when_enabled(): void
    {
        // No amount dimension → enabling means every suspension is held.
        config()->set('billing.approvals.actions', [
            'customer.suspend' => ['enabled' => true, 'threshold_minor' => null, 'required' => 1],
        ]);
        Organization::query()->create(['id' => 'org_s', 'name' => 'Suspend Co', 'billing_email' => 's@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        $this->withSession($this->maker)->post('/customers/org_s/suspend')->assertRedirect('/customers/org_s')->assertSessionHas('status');

        // Held — the org is NOT suspended yet.
        $this->assertNull(Organization::query()->find('org_s')?->suspended_at);
        $request = ApprovalRequest::query()->where('action_type', 'customer.suspend')->firstOrFail();
        $this->assertSame(ApprovalStatus::Pending, $request->status);

        // A different operator approves → the suspension applies.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')->assertSessionHas('status');
        $this->assertNotNull(Organization::query()->find('org_s')?->suspended_at);
        $this->assertSame(ApprovalStatus::Executed, $request->refresh()->status);
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'customer.suspended']);
    }

    public function test_the_checker_queue_renders_the_pending_request(): void
    {
        $invoice = $this->invoicedOrg();
        $this->heldRefund($invoice);

        $this->withSession($this->checker)->get('/approvals')
            ->assertOk()
            ->assertSee('Invoice · refund')
            ->assertSee('Maker');
    }
}
