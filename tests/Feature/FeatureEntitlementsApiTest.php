<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The boolean / non-metered feature-entitlements API — the product-gating sibling of the metered
 * `/entitlements/{org}`. Resolves an org's granted features from its serving plan's grants (+ any
 * org override), with the config-typed values, deny-by-default. The metered path is untouched.
 *
 * Seeded plan → feature vectors (CatalogSeeder): Starter grants `api_access` + `max_projects=3`
 * (no `sso`); Team grants `sso` + `analytics` + `api_access` + `max_projects=10`; Scale grants the
 * full set incl `saml` + `platform.multi_tenant`. Seeded org → plan (OrganizationSeeder): Klarhed
 * → Starter, Hverdag → Team, Fjord → Scale.
 */
class FeatureEntitlementsApiTest extends TestCase
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

    public function test_a_plan_granting_sso_and_a_config_value_resolves_both(): void
    {
        // Hverdag is on Team, which grants sso=true and max_projects=10.
        $response = $this->getJson('/api/v1/entitlements/org_hverdag/features', $this->tokenFor('org_hverdag'));

        $response->assertOk();
        $features = $response->json('features');

        $this->assertTrue($features['sso']['enabled']);
        $this->assertSame('boolean', $features['sso']['type']);
        $this->assertSame('plan', $features['sso']['source']);

        // The config value resolves as a real typed integer, not a string.
        $this->assertTrue($features['max_projects']['enabled']);
        $this->assertSame('config', $features['max_projects']['type']);
        $this->assertSame(10, $features['max_projects']['value']);
    }

    public function test_an_org_on_a_plan_without_the_feature_reports_it_disabled(): void
    {
        // Klarhed is on Starter, which does NOT grant sso — deny-by-default, reported not omitted.
        $response = $this->getJson('/api/v1/entitlements/org_klarhed/features', $this->tokenFor('org_klarhed'));

        $response->assertOk();
        $features = $response->json('features');

        $this->assertFalse($features['sso']['enabled']);
        $this->assertSame('default', $features['sso']['source']);

        // Its own granted features still resolve.
        $this->assertTrue($features['api_access']['enabled']);
        $this->assertSame(3, $features['max_projects']['value']);
    }

    public function test_single_feature_check_returns_the_typed_value(): void
    {
        $granted = $this->getJson('/api/v1/entitlements/org_hverdag/features/max_projects', $this->tokenFor('org_hverdag'));
        $granted->assertOk()
            ->assertJsonPath('key', 'max_projects')
            ->assertJsonPath('type', 'config')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('value', 10)
            ->assertJsonPath('source', 'plan');

        // A boolean check the org lacks resolves false (and carries an upgrade path — see below).
        $missing = $this->getJson('/api/v1/entitlements/org_klarhed/features/sso', $this->tokenFor('org_klarhed'));
        $missing->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_dotted_feature_keys_resolve_on_the_single_check(): void
    {
        // Fjord is on Scale, which grants the dotted key platform.multi_tenant.
        $this->getJson('/api/v1/entitlements/org_fjord/features/platform.multi_tenant', $this->tokenFor('org_fjord'))
            ->assertOk()
            ->assertJsonPath('key', 'platform.multi_tenant')
            ->assertJsonPath('enabled', true);
    }

    public function test_an_unknown_feature_is_deny_by_default_not_a_404(): void
    {
        $this->getJson('/api/v1/entitlements/org_hverdag/features/does.not.exist', $this->tokenFor('org_hverdag'))
            ->assertOk()
            ->assertJsonPath('key', 'does.not.exist')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('type', null)
            ->assertJsonPath('source', 'default');
    }

    public function test_a_missing_feature_carries_the_upgrade_cta(): void
    {
        // Klarhed (Starter) lacks sso; the cheapest reachable plan that grants it is Team.
        $set = $this->getJson('/api/v1/entitlements/org_klarhed/features', $this->tokenFor('org_klarhed'));
        $set->assertOk()
            ->assertJsonPath('features.sso.enabled', false)
            ->assertJsonPath('features.sso.upgrade.required_plan', 'team');

        $checkoutUrl = $set->json('features.sso.upgrade.checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertStringContainsString('/billing/checkout/', $checkoutUrl);

        // A granted feature carries no upgrade.
        $this->assertArrayNotHasKey('upgrade', $set->json('features.api_access'));

        // The single check carries the same CTA.
        $this->getJson('/api/v1/entitlements/org_klarhed/features/sso', $this->tokenFor('org_klarhed'))
            ->assertOk()
            ->assertJsonPath('upgrade.required_plan', 'team');
    }

    public function test_the_token_may_not_act_for_another_org(): void
    {
        $this->getJson('/api/v1/entitlements/org_fjord/features', $this->tokenFor('org_klarhed'))
            ->assertForbidden();
    }

    public function test_the_metered_entitlements_path_is_unchanged(): void
    {
        // The boolean sibling must not disturb the metered payload: it still answers with the
        // `meters` map and the same per-meter shape (enabled/allowance/weight/overage).
        $response = $this->getJson('/api/v1/entitlements/org_hverdag', $this->tokenFor('org_hverdag'));

        $response->assertOk();
        $meters = $response->json('meters');

        $this->assertArrayHasKey('api.requests', $meters);
        $this->assertArrayNotHasKey('features', $response->json());
        $this->assertTrue($meters['api.requests']['enabled']);
        $this->assertArrayHasKey('allowance', $meters['api.requests']);
        $this->assertArrayHasKey('overage', $meters['api.requests']);
    }

    public function test_an_org_with_no_subscription_denies_every_feature_by_default(): void
    {
        Organization::query()->create([
            'id' => 'org_fresh',
            'name' => 'Fresh',
            'billing_email' => 'fresh@example.test',
            'billing_country' => 'DK',
        ]);

        $response = $this->getJson('/api/v1/entitlements/org_fresh/features', $this->tokenFor('org_fresh'));
        $response->assertOk();

        foreach ($response->json('features') as $feature) {
            $this->assertFalse($feature['enabled']);
        }
    }
}
