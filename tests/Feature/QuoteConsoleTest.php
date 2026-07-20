<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Cpq\Enums\QuoteStatus;
use App\Models\OperatorAuditEvent;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Quotes console: authoring persists + lists; the approval threshold routes an above-threshold
 * quote to pending_approval and the send gate holds until it is approved; approve → send mints the
 * order-form token; the permission gates (`quotes:manage` / `quotes:approve`) and audit logging
 * hold.
 */
class QuoteConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|rep', 'name' => 'Rep One', 'email' => 'rep@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    /** A SECOND operator (not the quote owner) who approves — the two-person rule needs a distinct approver. */
    /** @var array<string, mixed> */
    private array $approver = ['auth.user' => [
        'sub' => 'demo|approver', 'name' => 'Approver Two', 'email' => 'approver@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    private function plan(): Plan
    {
        $product = Product::query()->create(['key' => 'cpq-prod', 'name' => 'CPQ Product', 'shape' => 'recurring']);
        $plan = Plan::query()->create(['product_id' => $product->id, 'key' => 'cpq-seat', 'name' => 'Seat Plan', 'interval' => 'month', 'active' => true]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => 20000, 'pricing_model' => 'per_unit']);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function payload(Plan $plan): array
    {
        return [
            'currency' => 'DKK',
            'term_count' => 12,
            'term_unit' => 'month',
            'billing_interval' => 'monthly',
            'prospect_name' => 'Acme Corp',
            'minimum_commitment' => '3000.00',
            'lines' => [
                ['type' => 'plan', 'plan_id' => $plan->id, 'quantity' => 25],
                ['type' => 'custom', 'description' => 'Onboarding', 'quantity' => 1, 'unit_amount' => '15000.00', 'recurring' => '0'],
            ],
        ];
    }

    public function test_authoring_persists_a_two_line_quote_and_lists_it(): void
    {
        $plan = $this->plan();

        $this->withSession($this->session)->post('/quotes', $this->payload($plan))->assertRedirect();

        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $quote->lines()->count());
        $this->assertSame('Acme Corp', $quote->prospect_name);
        $this->assertSame(300000, $quote->minimum_commitment_minor);

        $this->withSession($this->session)->get('/quotes')->assertOk()->assertSee($quote->number);
    }

    public function test_above_threshold_quote_routes_to_pending_approval_and_cannot_be_sent(): void
    {
        $plan = $this->plan();
        $this->withSession($this->session)->post('/quotes', $this->payload($plan))->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();

        // 25 × 200.00 + 15000.00 one-off = 20000.00 net; +25% VAT well above the 5000.00 default floor.
        $this->withSession($this->session)->post("/quotes/{$quote->id}/submit")->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::PendingApproval, $quote->status);
        $this->assertTrue($quote->approval_required);

        // The send GATE: a pending quote cannot be sent — it stays pending, no token is minted.
        $this->withSession($this->session)->post("/quotes/{$quote->id}/send")->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::PendingApproval, $quote->status);
        // Only the digest is ever persisted; a pending quote has none.
        $this->assertNull($quote->token_hash);
    }

    public function test_approve_then_send_mints_the_order_form_and_is_audit_logged(): void
    {
        $plan = $this->plan();
        $this->withSession($this->session)->post('/quotes', $this->payload($plan))->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->withSession($this->session)->post("/quotes/{$quote->id}/submit")->assertRedirect();

        // A SECOND operator (not the owner) approves — the two-person rule forbids self-approval.
        $this->withSession($this->approver)->post("/quotes/{$quote->id}/approve")->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::Approved, $quote->status);
        $this->assertSame('Approver Two', $quote->approved_by_name);

        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'quote.approved')->count());

        $send = $this->withSession($this->session)->post("/quotes/{$quote->id}/send")->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::Sent, $quote->status);
        // The order-form token is stored only as a SHA-256 digest; the plaintext link is flashed once.
        $this->assertNotNull($quote->token_hash);
        $this->assertSame(64, strlen((string) $quote->token_hash));
        $send->assertSessionHas('order_form_url');

        // The flashed URL still resolves, and its token hashes to exactly the stored digest — the
        // plaintext travels in the URL but only the digest is ever persisted.
        $url = (string) session('order_form_url');
        $plaintext = basename((string) parse_url($url, PHP_URL_PATH));
        $this->assertSame($quote->token_hash, hash('sha256', $plaintext));
        $this->get($url)->assertOk();
    }

    public function test_the_owner_cannot_approve_their_own_quote(): void
    {
        $plan = $this->plan();

        // The owner authors + submits an above-threshold quote (owner_sub = demo|rep).
        $this->withSession($this->session)->post('/quotes', $this->payload($plan))->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->withSession($this->session)->post("/quotes/{$quote->id}/submit")->assertRedirect();
        $this->assertSame(QuoteStatus::PendingApproval, $quote->refresh()->status);

        // The SAME operator (the owner) tries to approve — the two-person rule refuses it,
        // server-side. The quote stays pending and no approver is stamped.
        $this->withSession($this->session)->post("/quotes/{$quote->id}/approve")
            ->assertRedirect()
            ->assertSessionHas('error');

        $quote->refresh();
        $this->assertSame(QuoteStatus::PendingApproval, $quote->status);
        $this->assertNull($quote->approved_by_sub);
        $this->assertNull($quote->approved_at);

        // ...but a DIFFERENT operator with quotes:approve can approve it.
        $this->withSession($this->approver)->post("/quotes/{$quote->id}/approve")->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::Approved, $quote->status);
        $this->assertSame('demo|approver', $quote->approved_by_sub);
    }

    public function test_the_self_approval_guard_holds_even_with_the_approve_permission(): void
    {
        config()->set('billing.rbac.enforce', true);
        $plan = $this->plan();

        // An operator who holds BOTH quotes:manage AND quotes:approve authors + submits.
        $owner = ['auth.user' => [
            'sub' => 'demo|superrep', 'name' => 'Super Rep', 'email' => 'super@example.test', 'org' => 'org_hverdag',
            'picture' => null, 'permissions' => ['quotes:read', 'quotes:manage', 'quotes:approve'],
        ]];

        $this->withSession($owner)->post('/quotes', $this->payload($plan))->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->withSession($owner)->post("/quotes/{$quote->id}/submit")->assertRedirect();

        // The RBAC gate lets them through (they hold quotes:approve), but the maker-checker
        // guard still refuses — the quote is theirs. The refusal is enforced server-side.
        $this->withSession($owner)->post("/quotes/{$quote->id}/approve")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(QuoteStatus::PendingApproval, $quote->refresh()->status);
    }

    public function test_below_threshold_quote_is_auto_approved_on_submit(): void
    {
        $plan = $this->plan();
        // A single small line below the 5000.00 default amount floor and under the 25% discount gate.
        $this->withSession($this->session)->post('/quotes', [
            'currency' => 'DKK', 'term_count' => 12, 'term_unit' => 'month', 'billing_interval' => 'monthly',
            'prospect_name' => 'Small Co',
            'lines' => [['type' => 'plan', 'plan_id' => $plan->id, 'quantity' => 1]],
        ])->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();

        $this->withSession($this->session)->post("/quotes/{$quote->id}/submit")->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::Approved, $quote->status);
        $this->assertFalse($quote->approval_required);
    }

    public function test_the_manage_permission_gates_authoring(): void
    {
        config()->set('billing.rbac.enforce', true);
        $plan = $this->plan();

        $readOnly = ['auth.user' => [
            'sub' => 'demo|viewer', 'name' => 'Viewer', 'email' => 'v@example.test', 'org' => 'org_hverdag',
            'picture' => null, 'permissions' => ['quotes:read'],
        ]];

        $this->withSession($readOnly)->post('/quotes', $this->payload($plan))->assertStatus(403);
        $this->assertSame(0, Quote::query()->count());
    }

    public function test_the_approve_permission_is_distinct_from_manage(): void
    {
        config()->set('billing.rbac.enforce', true);
        $plan = $this->plan();

        $manager = ['auth.user' => [
            'sub' => 'demo|mgr', 'name' => 'Manager', 'email' => 'm@example.test', 'org' => 'org_hverdag',
            'picture' => null, 'permissions' => ['quotes:read', 'quotes:manage'],
        ]];

        // A manager can author + submit...
        $this->withSession($manager)->post('/quotes', $this->payload($plan))->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->withSession($manager)->post("/quotes/{$quote->id}/submit")->assertRedirect();

        // ...but cannot approve (needs quotes:approve).
        $this->withSession($manager)->post("/quotes/{$quote->id}/approve")->assertStatus(403);
        $this->assertSame(QuoteStatus::PendingApproval, $quote->refresh()->status);
    }

    public function test_the_console_pages_render(): void
    {
        $plan = $this->plan();
        $this->withSession($this->session)->post('/quotes', $this->payload($plan))->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();

        $this->withSession($this->session)->get("/quotes/{$quote->id}")->assertOk()->assertSee($quote->number)->assertSee('Committed value');
        $this->withSession($this->session)->get("/quotes/{$quote->id}/edit")->assertOk()->assertSee('Line items');
        $this->withSession($this->session)->get('/quotes/approvals')->assertOk()->assertSee('Approval queue');

        // Clone starts a fresh draft.
        $this->withSession($this->session)->post("/quotes/{$quote->id}/clone")->assertRedirect();
        $this->assertSame(2, Quote::query()->count());
    }

    public function test_a_provisioned_quote_cannot_be_edited(): void
    {
        $plan = $this->plan();
        $this->withSession($this->session)->post('/quotes', [
            'currency' => 'DKK', 'term_count' => 12, 'term_unit' => 'month', 'billing_interval' => 'monthly',
            'prospect_name' => 'Edit Co',
            'lines' => [['type' => 'plan', 'plan_id' => $plan->id, 'quantity' => 1]],
        ])->assertRedirect();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->withSession($this->session)->post("/quotes/{$quote->id}/submit")->assertRedirect();

        // Approved is no longer a draft — editing is refused.
        $this->withSession($this->session)->get("/quotes/{$quote->id}/edit")->assertRedirect(route('billing.quotes.show', $quote->id));
    }
}
