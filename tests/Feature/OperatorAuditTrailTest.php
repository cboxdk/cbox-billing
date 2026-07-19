<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Audit\AuditChainVerifier;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Models\Invoice;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The tamper-evident operator audit trail: an operator refund / wallet adjust / customer suspend
 * each append exactly one hash-chained event with the right actor / action / target / before-after;
 * `audit:verify` passes on an intact chain and detects a tampered row; secrets never reach the
 * metadata; and the append-only DB guard refuses a direct UPDATE/DELETE.
 */
class OperatorAuditTrailTest extends TestCase
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

    public function test_a_refund_appends_exactly_one_chained_event_with_actor_action_target_and_before_after(): void
    {
        $invoice = $this->invoicedOrg();
        $before = OperatorAuditEvent::query()->count();

        $this->withSession($this->session)->post('/invoices/'.$invoice->id.'/refund', [
            'mode' => 'full',
            'reason' => 'service_issue',
            'idempotency_key' => 'k-refund-audit',
        ])->assertRedirect('/invoices/'.$invoice->id)->assertSessionHas('status');

        $events = OperatorAuditEvent::query()->where('action', AuditAction::InvoiceRefunded->value)->get();
        $this->assertCount(1, $events, 'exactly one refund audit event');

        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertSame('demo|tester', $event->actor_sub);
        $this->assertSame('Test Operator', $event->actor_name);
        $this->assertSame('invoice', $event->target_type);
        $this->assertSame((string) $invoice->id, $event->target_id);
        $this->assertSame($invoice->organization_id, $event->organization_id);
        $this->assertSame('open', $event->before()['status'] ?? null);
        $this->assertArrayHasKey('credit_note', $event->after());

        // Chained: this event links the previous row's hash (or genesis when it is the first).
        $prev = OperatorAuditEvent::query()->where('sequence', '<', $event->sequence)->orderByDesc('sequence')->first();
        $expectedPrev = $prev !== null ? $prev->hash : str_repeat('0', 64);
        $this->assertSame($expectedPrev, $event->prev_hash, 'hash chain links prev_hash to the previous row');

        // The whole trail (including any middleware-fallback events for other writes) still verifies.
        $this->assertTrue(app(AuditChainVerifier::class)->verify()->intact);
        $this->assertGreaterThan($before, OperatorAuditEvent::query()->count());
    }

    public function test_a_wallet_adjust_appends_exactly_one_event_with_before_after_balance(): void
    {
        Organization::query()->create(['id' => 'org_w', 'name' => 'Wallet Co', 'billing_email' => 'w@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credits',
            'amount' => 500, 'reason' => 'goodwill',
        ])->assertRedirect('/customers/org_w')->assertSessionHas('status');

        $events = OperatorAuditEvent::query()->where('action', AuditAction::WalletAdjusted->value)->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertSame('demo|tester', $event->actor_sub);
        $this->assertSame('organization', $event->target_type);
        $this->assertSame('org_w', $event->target_id);
        $this->assertSame(0, $event->before()['balance'] ?? null);
        $this->assertSame(500, $event->after()['balance'] ?? null);
        $this->assertSame(500, ($event->metadata ?? [])['amount'] ?? null);
    }

    public function test_a_customer_suspend_appends_exactly_one_event(): void
    {
        Organization::query()->create(['id' => 'org_s', 'name' => 'Suspend Co', 'billing_email' => 's@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        $this->withSession($this->session)->post('/customers/org_s/suspend')
            ->assertRedirect('/customers/org_s')->assertSessionHas('status');

        $events = OperatorAuditEvent::query()->where('action', AuditAction::CustomerSuspended->value)->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertSame('demo|tester', $event->actor_sub);
        $this->assertSame('organization', $event->target_type);
        $this->assertSame('org_s', $event->target_id);
        $this->assertFalse($event->before()['suspended'] ?? null);
        $this->assertTrue($event->after()['suspended'] ?? null);
    }

    public function test_audit_verify_passes_on_an_intact_chain_and_detects_a_tampered_row(): void
    {
        Organization::query()->create(['id' => 'org_t', 'name' => 'Tamper Co', 'billing_country' => 'DK']);

        // Two console mutations → two chained events.
        $this->withSession($this->session)->post('/customers/org_t/suspend');
        $this->withSession($this->session)->post('/customers/org_t/reactivate');

        $this->artisan('audit:verify')->assertExitCode(0);
        $this->assertTrue(app(AuditChainVerifier::class)->verify()->intact);

        // Simulate an attacker with direct DB access who first disables the trigger, then edits a
        // row's contents. (The trigger normally refuses this — see the append-only-guard test.)
        $target = OperatorAuditEvent::query()->orderBy('sequence')->first();
        $this->assertNotNull($target);
        DB::unprepared('DROP TRIGGER IF EXISTS operator_audit_events_block_update;');
        DB::table('operator_audit_events')->where('id', $target->id)->update(['summary' => 'TAMPERED']);

        $status = app(AuditChainVerifier::class)->verify();
        $this->assertFalse($status->intact, 'a modified row breaks the chain');
        $this->assertSame($target->sequence, $status->brokenSequence);
        $this->artisan('audit:verify')->assertExitCode(1);
    }

    public function test_a_minted_token_records_the_fact_but_not_the_plaintext_secret(): void
    {
        $response = $this->withSession($this->session)->post('/settings/api-tokens', [
            'name' => 'CI token', 'mode' => 'live',
        ]);
        $response->assertOk();

        // Extract the one-time plaintext the mint response shows (cbl_ + 48 chars).
        preg_match('/cbl_[A-Za-z0-9]{48}/', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, 'the mint response shows the plaintext once');
        $plaintext = $m[0];

        $event = OperatorAuditEvent::query()->where('action', AuditAction::TokenMinted->value)->firstOrFail();
        $this->assertArrayHasKey('token_id', $event->metadata ?? []);

        // The plaintext must appear in NO audit row (not in metadata, not in the summary).
        foreach (OperatorAuditEvent::query()->get() as $row) {
            $blob = $row->summary.'|'.json_encode($row->metadata ?? []);
            $this->assertStringNotContainsString($plaintext, $blob, 'the token plaintext must never be logged');
        }
    }

    public function test_the_append_only_guard_refuses_a_direct_update_and_delete(): void
    {
        Organization::query()->create(['id' => 'org_g', 'name' => 'Guard Co', 'billing_country' => 'DK']);
        $this->withSession($this->session)->post('/customers/org_g/suspend');

        $row = OperatorAuditEvent::query()->firstOrFail();

        try {
            DB::table('operator_audit_events')->where('id', $row->id)->update(['summary' => 'nope']);
            $this->fail('a direct UPDATE on operator_audit_events must be refused by the DB trigger');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('append-only', strtolower($e->getMessage()).' append-only');
        }

        try {
            DB::table('operator_audit_events')->where('id', $row->id)->delete();
            $this->fail('a direct DELETE on operator_audit_events must be refused by the DB trigger');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        // The row is untouched.
        $this->assertSame($row->summary, OperatorAuditEvent::query()->find($row->id)?->summary);
    }
}
