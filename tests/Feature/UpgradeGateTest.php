<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Enforcement\Upgrade\ResolvesRequiredPlan;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The enforce→upgrade bridge (ADR-0009/#52): an enforcement denial or a disabled meter
 * carries the path to unlock — the minimum reachable plan that grants the blocking meter
 * and a pre-built hosted-checkout deep-link to buy it — and carries nothing when the org is
 * already on the best plan or no plan grants the meter.
 */
class UpgradeGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    /** @return array<string, string> */
    private function tokenFor(string $org): array
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_reserve_denial_on_disabled_meter_carries_required_plan_and_checkout_deeplink(): void
    {
        // org_klarhed is on Starter, where events.ingested is disabled — reserving it is a
        // deny-by-default refusal that must carry the upgrade to the cheapest plan that
        // enables the meter (Team).
        $response = $this->postJson('/api/v1/reserve', [
            'org' => 'org_klarhed',
            'meters' => [['meter' => 'events.ingested', 'estimate' => 1]],
        ], $this->tokenFor('org_klarhed'));

        $response->assertOk()
            ->assertJsonPath('outcome', 'denied')
            ->assertJsonPath('reason', 'disabled_meter')
            ->assertJsonPath('upgrade.required_plan', 'team');

        $checkoutUrl = $response->json('upgrade.checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertStringContainsString('/billing/checkout/', $checkoutUrl);

        // The deep-link resolves to a real pending checkout session for the Team plan.
        $token = basename((string) parse_url($checkoutUrl, PHP_URL_PATH));
        $session = BillingSession::query()->where('token', $token)->first();

        $this->assertNotNull($session);
        $this->assertSame('org_klarhed', $session->organization_id);
        $this->assertSame('team', $session->plan_key);
        $this->assertSame('checkout', $session->type->value);
        $this->assertTrue($session->isUsable());
    }

    public function test_reserve_denial_with_no_granting_plan_carries_no_upgrade(): void
    {
        // No plan carries an entitlement for an unknown meter, so a refusal on it has no
        // upgrade path — deny-by-default, no fabricated target.
        $response = $this->postJson('/api/v1/reserve', [
            'org' => 'org_fjord',
            'meters' => [['meter' => 'nonexistent.meter', 'estimate' => 1]],
        ], $this->tokenFor('org_fjord'));

        $response->assertOk()->assertJsonPath('outcome', 'denied');
        $this->assertNull($response->json('upgrade'));
    }

    public function test_entitlements_attach_upgrade_only_to_disabled_meters_with_a_path(): void
    {
        $response = $this->getJson('/api/v1/entitlements/org_klarhed', $this->tokenFor('org_klarhed'));

        $response->assertOk();

        // Meter keys contain dots, so index the decoded payload rather than a dotted path.
        $meters = $response->json('meters');

        $this->assertFalse($meters['events.ingested']['enabled']);
        $this->assertSame('team', $meters['events.ingested']['upgrade']['required_plan']);

        // An enabled meter carries no upgrade.
        $this->assertArrayNotHasKey('upgrade', $meters['api.requests']);
    }

    public function test_entitlements_for_org_without_subscription_offer_the_cheapest_enabling_plan(): void
    {
        Organization::query()->create([
            'id' => 'org_fresh',
            'name' => 'Fresh',
            'billing_email' => 'fresh@example.test',
            'billing_country' => 'DK',
        ]);

        $response = $this->getJson('/api/v1/entitlements/org_fresh', $this->tokenFor('org_fresh'));

        $response->assertOk();

        // Every meter is disabled deny-by-default (no subscription resolves a policy); each
        // offers the minimum offered plan that grants it.
        $meters = $response->json('meters');

        $this->assertFalse($meters['api.requests']['enabled']);
        $this->assertSame('starter', $meters['api.requests']['upgrade']['required_plan']);
        $this->assertSame('team', $meters['events.ingested']['upgrade']['required_plan']);
    }

    public function test_resolver_returns_null_when_already_on_the_top_plan(): void
    {
        $resolver = app(ResolvesRequiredPlan::class);

        // org_fjord is on Scale: storage.gb (10,000 — the ladder maximum) cannot be improved
        // and api.requests is unlimited, so neither has an upgrade target.
        $this->assertNull($resolver->resolve('org_fjord', 'storage.gb'));
        $this->assertNull($resolver->resolve('org_fjord', 'api.requests'));
    }

    public function test_resolver_offers_the_next_larger_plan_for_an_exhaustible_meter(): void
    {
        $resolver = app(ResolvesRequiredPlan::class);

        // org_hverdag is on Team (api.requests 1,000,000); the cheapest reachable plan with a
        // larger allowance is Business (5,000,000), not Scale.
        $this->assertSame('business', $resolver->resolve('org_hverdag', 'api.requests')?->key);
    }

    public function test_checkout_session_is_reused_across_repeated_denials(): void
    {
        $auth = $this->tokenFor('org_klarhed');

        $this->getJson('/api/v1/entitlements/org_klarhed', $auth)->assertOk();
        $this->postJson('/api/v1/reserve', [
            'org' => 'org_klarhed',
            'meters' => [['meter' => 'events.ingested', 'estimate' => 1]],
        ], $auth)->assertOk();

        // One pre-built checkout for (org, Team) is minted and reused, not one per denial.
        $this->assertSame(1, BillingSession::query()
            ->where('organization_id', 'org_klarhed')
            ->where('plan_key', 'team')
            ->count());
    }
}
