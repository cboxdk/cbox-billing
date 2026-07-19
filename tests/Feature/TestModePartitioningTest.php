<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Mode\LivemodeScope;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The livemode plane partition: a test credential sees and writes only `livemode=false` rows,
 * a live one only `livemode=true` rows, and neither can reach across the boundary. Isolation
 * is enforced by the global {@see LivemodeScope}, deny-by-default.
 */
class TestModePartitioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_the_global_scope_isolates_each_plane_and_denies_cross_mode_lookups(): void
    {
        $context = app(BillingContext::class);

        $context->setMode(BillingMode::Live);
        Organization::query()->create(['id' => 'org_live', 'name' => 'Live', 'billing_country' => 'DK']);

        $context->setMode(BillingMode::Test);
        Organization::query()->create(['id' => 'org_test', 'name' => 'Test', 'billing_country' => 'DK']);

        // Rows are stamped by the mode they were created in.
        $this->assertDatabaseHas('organizations', ['id' => 'org_live', 'livemode' => true]);
        $this->assertDatabaseHas('organizations', ['id' => 'org_test', 'livemode' => false]);

        // In test mode only the test row is visible; the live row is a cross-mode 404.
        $this->assertSame(['org_test'], Organization::query()->pluck('id')->all());
        $this->assertNull(Organization::query()->find('org_live'));

        // In live mode the mirror holds.
        $context->setMode(BillingMode::Live);
        $this->assertSame(['org_live'], Organization::query()->pluck('id')->all());
        $this->assertNull(Organization::query()->find('org_test'));

        // Both rows really exist — isolation is a scope, not a delete.
        $this->assertSame(2, Organization::query()->withoutGlobalScopes()->count());
    }

    public function test_a_test_token_reaches_only_the_sandbox_and_a_live_token_cannot(): void
    {
        $context = app(BillingContext::class);

        // A sandbox org + subscription (livemode=false).
        $context->setMode(BillingMode::Test);
        Organization::query()->create(['id' => 'org_sandbox', 'name' => 'Sandbox', 'billing_country' => 'DK']);
        Subscription::query()->create([
            'organization_id' => 'org_sandbox',
            'plan_id' => Plan::query()->where('key', 'starter')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse('2026-01-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-02-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);
        $context->setMode(BillingMode::Live);

        ['plaintext' => $testToken] = ApiToken::issue('sandbox', null, null, null, BillingMode::Test);
        ['plaintext' => $liveToken] = ApiToken::issue('production', null, null, null, BillingMode::Live);

        // The test token sees the sandbox subscription.
        $this->getJson('/api/v1/subscriptions/org_sandbox', ['Authorization' => 'Bearer '.$testToken])
            ->assertOk()
            ->assertJsonPath('plan', 'starter');

        // The live operator token — allowed to act for any org — still cannot see it: the plane
        // scope hides the row, so it 404s.
        $this->getJson('/api/v1/subscriptions/org_sandbox', ['Authorization' => 'Bearer '.$liveToken])
            ->assertNotFound();
    }

    public function test_a_test_token_carries_a_prefixed_plaintext_and_resolves_test_mode(): void
    {
        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::issue('sb', null, null, null, BillingMode::Test);

        $this->assertStringStartsWith('cbt_', $plaintext);
        $this->assertSame('test', $token->mode);
        $this->assertTrue($token->isTestMode());
    }
}
