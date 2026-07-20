<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Features\Enums\ConfigValueType;
use App\Billing\Features\Enums\FeatureType;
use App\Billing\Features\FeatureEntitlements;
use App\Models\Feature;
use App\Models\OrganizationFeatureOverride;
use App\Models\Plan;
use App\Models\PlanFeature;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Features console CRUD, the plan-grant authoring on the plan detail hub, and the org-level
 * override on the customer detail page (audit-logged). Covers persistence, the referential-delete
 * guard, that a grant/override flows into the resolver, and that the override is recorded to the
 * tamper-evident audit trail.
 */
class FeatureCatalogConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_index_renders_seeded_features(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->get('/features')
            ->assertOk()
            ->assertSee('Single sign-on');
    }

    public function test_the_plan_detail_renders_the_feature_grants_section(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $this->withSession($this->session)->get('/catalog/plans/'.$team->id)
            ->assertOk()
            ->assertSee('Features')
            ->assertSee('Single sign-on')
            ->assertSee('Add feature grant');
    }

    public function test_the_customer_detail_renders_the_feature_entitlements_panel(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        // Hverdag is on Team → its resolved features (incl. granted sso) show with the override UI.
        $this->withSession($this->session)->get('/customers/org_hverdag')
            ->assertOk()
            ->assertSee('Feature entitlements')
            ->assertSee('Apply override');
    }

    public function test_a_guest_is_gated_off_the_console(): void
    {
        $this->seed(CatalogSeeder::class);

        // No operator session → the auth gate refuses (redirect to login), so the write surface
        // is not reachable without authentication.
        $this->get('/features')->assertRedirect('/login');
        $this->post('/features', ['key' => 'x', 'name' => 'X', 'type' => 'boolean'])->assertRedirect('/login');
    }

    public function test_create_persists_a_boolean_and_a_config_feature(): void
    {
        $this->withSession($this->session)->post('/features', [
            'key' => 'priority_support',
            'name' => 'Priority support',
            'description' => 'Commercial support tier.',
            'type' => 'boolean',
        ])->assertRedirect()->assertSessionHas('status');

        $boolean = Feature::query()->where('key', 'priority_support')->firstOrFail();
        $this->assertSame(FeatureType::Boolean, $boolean->type);
        $this->assertNull($boolean->value_type);

        $this->withSession($this->session)->post('/features', [
            'key' => 'max_seats',
            'name' => 'Max seats',
            'type' => 'config',
            'value_type' => 'integer',
        ])->assertRedirect()->assertSessionHas('status');

        $config = Feature::query()->where('key', 'max_seats')->firstOrFail();
        $this->assertSame(FeatureType::Config, $config->type);
        $this->assertSame(ConfigValueType::Integer, $config->value_type);
    }

    public function test_delete_is_guarded_when_a_plan_grants_the_feature(): void
    {
        $this->seed(CatalogSeeder::class);
        $feature = Feature::query()->where('key', 'sso')->firstOrFail(); // granted by Team/Business/Scale

        $this->withSession($this->session)->delete('/features/'.$feature->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('features', ['id' => $feature->id]);

        // Archive is the offered path instead.
        $this->withSession($this->session)->post('/features/'.$feature->id.'/archive')->assertRedirect();
        $this->assertNotNull($feature->fresh()?->archived_at);
    }

    public function test_delete_succeeds_for_an_unreferenced_feature(): void
    {
        $feature = Feature::query()->create(['key' => 'orphan', 'name' => 'Orphan', 'type' => FeatureType::Boolean]);

        $this->withSession($this->session)->delete('/features/'.$feature->id)
            ->assertRedirect('/features')
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('features', ['id' => $feature->id]);
    }

    public function test_plan_grant_authoring_persists_and_flows_into_the_resolver(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();
        $sso = Feature::query()->where('key', 'sso')->firstOrFail();

        // Grant sso to Starter (which did not have it) from the plan detail hub.
        $this->withSession($this->session)->post('/catalog/plans/'.$starter->id.'/features', [
            'feature_id' => $sso->id,
            'enabled' => '1',
        ])->assertRedirect('/catalog/plans/'.$starter->id)->assertSessionHas('status');

        $this->assertDatabaseHas('plan_features', ['plan_id' => $starter->id, 'feature_id' => $sso->id, 'enabled' => true]);

        // Klarhed is on Starter → the new grant resolves for it.
        $this->assertTrue(app(FeatureEntitlements::class)->has('org_klarhed', 'sso'));
    }

    public function test_a_config_grant_stores_and_types_its_value(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();
        $maxProjects = Feature::query()->where('key', 'max_projects')->firstOrFail();
        $grant = PlanFeature::query()->where('plan_id', $starter->id)->where('feature_id', $maxProjects->id)->firstOrFail();

        $this->withSession($this->session)->put('/catalog/plans/'.$starter->id.'/features/'.$grant->id, [
            'feature_id' => $maxProjects->id,
            'enabled' => '1',
            'value' => '7',
        ])->assertRedirect('/catalog/plans/'.$starter->id);

        app(FeatureEntitlements::class)->flush();
        $this->assertSame(7, app(FeatureEntitlements::class)->resolve('org_klarhed', 'max_projects')->value);
    }

    public function test_org_override_grants_a_feature_the_plan_lacks_and_is_audit_logged(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        $sso = Feature::query()->where('key', 'sso')->firstOrFail();

        // Klarhed (Starter) has no sso — grant it at the org level.
        $this->withSession($this->session)->post('/customers/org_klarhed/features/override', [
            'feature_id' => $sso->id,
            'direction' => 'grant',
            'reason' => 'Enterprise pilot',
        ])->assertRedirect('/customers/org_klarhed')->assertSessionHas('status');

        $override = OrganizationFeatureOverride::query()->where('organization_id', 'org_klarhed')->where('feature_id', $sso->id)->firstOrFail();
        $this->assertTrue($override->granted);

        app(FeatureEntitlements::class)->flush();
        $resolved = app(FeatureEntitlements::class)->resolve('org_klarhed', 'sso');
        $this->assertTrue($resolved->enabled);
        $this->assertSame('override', $resolved->source->value);

        // The grant is recorded to the tamper-evident operator audit trail.
        $this->assertDatabaseHas('operator_audit_events', [
            'action' => 'customer.feature_overridden',
            'organization_id' => 'org_klarhed',
        ]);
    }

    public function test_clearing_the_override_restores_the_plan_resolved_value(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        $sso = Feature::query()->where('key', 'sso')->firstOrFail();

        // Grant, then clear.
        $this->withSession($this->session)->post('/customers/org_klarhed/features/override', [
            'feature_id' => $sso->id, 'direction' => 'grant',
        ])->assertRedirect();

        $this->withSession($this->session)->post('/customers/org_klarhed/features/clear', [
            'feature_id' => $sso->id,
        ])->assertRedirect('/customers/org_klarhed')->assertSessionHas('status');

        $this->assertDatabaseMissing('organization_feature_overrides', ['organization_id' => 'org_klarhed', 'feature_id' => $sso->id]);

        // Back to the plan-resolved answer: Starter does not grant sso.
        app(FeatureEntitlements::class)->flush();
        $this->assertFalse(app(FeatureEntitlements::class)->has('org_klarhed', 'sso'));
    }

    public function test_an_override_can_revoke_a_feature_the_plan_grants(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        $sso = Feature::query()->where('key', 'sso')->firstOrFail();

        // Hverdag (Team) HAS sso by plan — revoke it at the org level.
        $this->withSession($this->session)->post('/customers/org_hverdag/features/override', [
            'feature_id' => $sso->id, 'direction' => 'revoke', 'reason' => 'Contract exclusion',
        ])->assertRedirect();

        app(FeatureEntitlements::class)->flush();
        $resolved = app(FeatureEntitlements::class)->resolve('org_hverdag', 'sso');
        $this->assertFalse($resolved->enabled);
        $this->assertSame('override', $resolved->source->value);
    }
}
