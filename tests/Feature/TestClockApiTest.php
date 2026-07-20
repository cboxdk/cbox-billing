<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TestClock;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The programmatic test-clock advance endpoint: a test-mode token can fast-forward a clock and
 * see the due billing logic fire; a live token is refused (deny-by-default).
 */
class TestClockApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        $this->seedConfigInAllPlanes(CatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_test_token_advances_a_clock_and_fires_the_due_renewal(): void
    {
        $clock = $this->sandboxClock('org_api_tc');

        ['plaintext' => $token] = ApiToken::issue('sandbox', null, null, null, BillingMode::Test);

        $this->postJson("/api/v1/test/clocks/{$clock->id}/advance", ['target' => '2026-02-15T00:00:00Z'], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('renewals', 1)
            ->assertJsonPath('invoices', 1);
    }

    public function test_a_live_token_is_refused(): void
    {
        $clock = $this->sandboxClock('org_api_live');

        ['plaintext' => $token] = ApiToken::issue('production', null, null, null, BillingMode::Live);

        $this->postJson("/api/v1/test/clocks/{$clock->id}/advance", ['target' => '2026-02-15T00:00:00Z'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_an_org_scoped_test_token_cannot_advance_another_orgs_clock(): void
    {
        // Two org-scoped clocks; a test token for org A must not fast-forward org B's clock.
        $clockA = $this->sandboxClock('org_tc_a', scopeToOrg: true);
        $clockB = $this->sandboxClock('org_tc_b', scopeToOrg: true);

        ['plaintext' => $token] = ApiToken::issue('a-sandbox', 'org_tc_a', null, null, BillingMode::Test);
        $auth = ['Authorization' => 'Bearer '.$token];

        // Cross-org advance is refused (403); the token may act only for its own org's clock.
        $this->postJson("/api/v1/test/clocks/{$clockB->id}/advance", ['target' => '2026-02-15T00:00:00Z'], $auth)
            ->assertForbidden();

        // Its own org's clock still advances.
        $this->postJson("/api/v1/test/clocks/{$clockA->id}/advance", ['target' => '2026-02-15T00:00:00Z'], $auth)
            ->assertOk();
    }

    private function sandboxClock(string $orgId, bool $scopeToOrg = false): TestClock
    {
        $context = app(BillingContext::class);
        $context->setMode(BillingMode::Test);

        $org = Organization::query()->create([
            'id' => $orgId,
            'name' => ucfirst($orgId),
            'billing_country' => 'DK',
            'billing_email' => 'billing@'.$orgId.'.test',
        ]);
        $plan = Plan::query()->where('key', 'starter')->with('prices', 'product')->firstOrFail();
        $subscription = app(SubscribesOrganizations::class)->subscribe($org, $plan);

        $clock = TestClock::query()->create([
            'name' => 'api-clock',
            'organization_id' => $scopeToOrg ? $orgId : null,
            'now_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        ]);
        $subscription->forceFill(['test_clock_id' => $clock->id])->save();

        $context->setMode(BillingMode::Live);

        return $clock;
    }
}
