<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\Exceptions\BillingCurrencyMismatch;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The self-service management API: the subscription lifecycle (subscribe → preview →
 * change → cancel), the usage summary, the invoice list, and multi-currency pricing with
 * the one-way currency lock. Each call is driven through the real HTTP surface, the #41
 * lifecycle services, and the engine — only the catalog is seeded.
 */
class ManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /** @return array{0: Organization, 1: array<string, string>} */
    private function orgWithToken(string $id, string $currency = 'DKK'): array
    {
        $organization = Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($id.'-sdk', $id);

        return [$organization, ['Authorization' => 'Bearer '.$token]];
    }

    public function test_subscribe_preview_change_and_cancel(): void
    {
        [, $auth] = $this->orgWithToken('org_lifecycle');

        // --- Subscribe (payment_intent reserved for the intents task) ---
        $subscribe = $this->postJson('/api/v1/subscriptions', [
            'org' => 'org_lifecycle',
            'plan' => 'starter',
            'seats' => 2,
        ], $auth);

        $subscribe->assertCreated()
            ->assertJsonPath('subscription.plan', 'starter')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('payment_intent', null);

        // The account pinned its chosen currency (default DKK) on first subscribe.
        $this->assertSame('DKK', Organization::query()->find('org_lifecycle')?->billing_currency);

        // --- Read the current subscription ---
        $this->getJson('/api/v1/subscriptions/org_lifecycle', $auth)
            ->assertOk()
            ->assertJsonPath('plan', 'starter')
            ->assertJsonPath('status', 'active');

        // --- Preview an upgrade to Team: money is due now, priced in DKK ---
        $preview = $this->postJson('/api/v1/subscriptions/org_lifecycle/preview', [
            'plan' => 'team',
        ], $auth);

        $preview->assertOk()
            ->assertJsonPath('new_recurring_minor', 124_000)
            ->assertJsonPath('credit_minor', 0);

        $this->assertGreaterThan(0, $preview->json('due_now_minor'));
        $this->assertNotEmpty($preview->json('lines'));

        // Preview does not mutate: still on Starter.
        $this->getJson('/api/v1/subscriptions/org_lifecycle', $auth)->assertJsonPath('plan', 'starter');

        // --- Apply the change (same consequence as the preview) ---
        $change = $this->postJson('/api/v1/subscriptions/org_lifecycle/change', [
            'plan' => 'team',
        ], $auth);

        $change->assertOk()->assertJsonPath('new_recurring_minor', 124_000);
        $this->assertSame($preview->json('due_now_minor'), $change->json('due_now_minor'));

        $this->getJson('/api/v1/subscriptions/org_lifecycle', $auth)->assertJsonPath('plan', 'team');

        // --- Cancel at period end: stays active, no longer renews ---
        $this->postJson('/api/v1/subscriptions/org_lifecycle/cancel', [
            'at_period_end' => true,
        ], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('renews_at', null);

        // --- Cancel immediately: forfeiture-on-transition via the engine lifecycle ---
        $this->postJson('/api/v1/subscriptions/org_lifecycle/cancel', [
            'at_period_end' => false,
        ], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'canceled');
    }

    public function test_usage_summary_reports_used_against_allowance(): void
    {
        [$organization, $auth] = $this->orgWithToken('org_usage');
        app(SubscribesOrganizations::class)->subscribe($organization, Plan::query()->where('key', 'team')->firstOrFail());

        // Land some usage through the enforcement ingest (durable event log).
        $this->postJson('/api/v1/usage', [
            'org' => 'org_usage',
            'entries' => [['meter' => 'events.ingested', 'cumulative' => 1_200, 'seq' => 1]],
        ], $auth)->assertOk();

        $usage = $this->getJson('/api/v1/usage/org_usage', $auth);
        $usage->assertOk();

        // Meter keys contain dots, so read the array directly rather than via dotted paths.
        $meters = $usage->json('meters');
        $this->assertIsArray($meters);
        $this->assertSame(1_200, $meters['events.ingested']['used']);
        $this->assertSame(500_000, $meters['events.ingested']['allowance']);
        $this->assertSame(0, $meters['events.ingested']['overage']);
        $this->assertSame(1_000_000, $meters['api.requests']['allowance']);

        $this->assertNotNull($usage->json('period.start'));
        $this->assertNotNull($usage->json('period.end'));
    }

    public function test_invoices_list_returns_issued_invoices(): void
    {
        [$organization, $auth] = $this->orgWithToken('org_invoices');
        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, Plan::query()->where('key', 'starter')->firstOrFail());

        $invoice = app(GeneratesInvoices::class)->generate($subscription->refresh());

        $this->getJson('/api/v1/invoices/org_invoices', $auth)
            ->assertOk()
            ->assertJsonPath('data.0.number', $invoice->number)
            ->assertJsonPath('data.0.currency', 'DKK')
            ->assertJsonPath('data.0.amount_minor', $invoice->total_minor)
            ->assertJsonPath('data.0.status', 'open');
    }

    public function test_plans_are_priced_in_the_requested_currency(): void
    {
        [, $auth] = $this->orgWithToken('org_catalog');

        // Explicit signup currency overrides the caller's account currency.
        $eur = $this->getJson('/api/v1/plans?currency=EUR', $auth);
        $eur->assertOk()->assertJsonPath('currency', 'EUR');

        $team = collect($eur->json('data'))->firstWhere('key', 'team');
        $this->assertSame(16_900, $team['price']['minor']);
        $this->assertSame('EUR', $team['price']['currency']);
        $this->assertArrayHasKey('api.requests', $team['entitlements']);

        // Without a currency, the caller's account currency (DKK) is used.
        $dkk = $this->getJson('/api/v1/plans', $auth);
        $dkk->assertOk()->assertJsonPath('currency', 'DKK');
        $this->assertSame(124_000, collect($dkk->json('data'))->firstWhere('key', 'team')['price']['minor']);
    }

    public function test_eur_account_is_invoiced_in_eur_and_its_currency_locks(): void
    {
        [$organization, $auth] = $this->orgWithToken('org_eur');

        // Choose EUR at signup. 20 seats: `team` is graduated (first 10 seats free, then
        // 1 300/seat in EUR), so the seat-aware recurring charge the invoice bills is
        // 10 × 1 300 = 13 000 EUR — through the engine, not the raw base price.
        $this->postJson('/api/v1/subscriptions', [
            'org' => 'org_eur',
            'plan' => 'team',
            'currency' => 'EUR',
            'seats' => 20,
        ], $auth)->assertCreated();

        $this->assertSame('EUR', $organization->refresh()->billing_currency);

        $subscription = $organization->subscriptions()->firstOrFail();
        $invoice = app(GeneratesInvoices::class)->generate($subscription);

        // Priced and invoiced in EUR (Team graduated @ 20 seats = 13 000 minor; DK VAT 25%).
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame(13_000, $invoice->subtotal_minor);
        $this->assertSame(3_250, $invoice->tax_minor);
        $this->assertSame(16_250, $invoice->total_minor);

        // The first finalized invoice locked the account's currency, one-way.
        $lock = app(BillingCurrencyLock::class);
        $this->assertSame('EUR', $lock->lockedCurrency('org_eur'));

        // A later finalization in a different currency is refused.
        $this->expectException(BillingCurrencyMismatch::class);
        $lock->stampAndGuard('org_eur', 'USD', static fn (): bool => true);
    }

    public function test_management_api_enforces_per_org_scope(): void
    {
        Organization::query()->create(['id' => 'org_a', 'name' => 'A', 'billing_country' => 'DK']);
        Organization::query()->create(['id' => 'org_b', 'name' => 'B', 'billing_country' => 'DK']);

        ['plaintext' => $token] = ApiToken::issue('a-sdk', 'org_a');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/subscriptions/org_b', $auth)->assertForbidden();
        $this->getJson('/api/v1/invoices/org_b', $auth)->assertForbidden();
        $this->getJson('/api/v1/usage/org_b', $auth)->assertForbidden();
        $this->postJson('/api/v1/subscriptions', ['org' => 'org_b', 'plan' => 'starter'], $auth)->assertForbidden();
    }

    public function test_management_api_denies_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/plans')->assertUnauthorized();
        $this->getJson('/api/v1/subscriptions/org_x')->assertUnauthorized();
    }
}
