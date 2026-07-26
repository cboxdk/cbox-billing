<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PUT /api/v1/organizations/{org}` — merchant platforms provision the orgs they bill
 * for on demand. Idempotent; currency only ever applied on create (one-way lock).
 */
class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function operatorAuth(): array
    {
        config(['billing.api.static_token' => 'operator-token']);

        return ['Authorization' => 'Bearer operator-token'];
    }

    public function test_operator_creates_then_updates_an_organization(): void
    {
        $auth = $this->operatorAuth();

        $this->putJson('/api/v1/organizations/tenant_01', [
            'name' => 'Acme Support',
            'billing_email' => 'billing@acme.test',
            'billing_currency' => 'usd',
            'billing_country' => 'DK',
        ], $auth)->assertCreated()
            // The caller's key is echoed back as external_ref; the id itself is an opaque ULID,
            // so it cannot be guessed on the unauthenticated paywall endpoint.
            ->assertJsonPath('organization.external_ref', 'tenant_01')
            ->assertJsonPath('organization.billing_currency', 'USD');

        // Re-send with a changed name and a DIFFERENT currency: name updates, currency stays.
        $this->putJson('/api/v1/organizations/tenant_01', [
            'name' => 'Acme Support ApS',
            'billing_currency' => 'EUR',
        ], $auth)->assertOk()
            ->assertJsonPath('organization.name', 'Acme Support ApS')
            ->assertJsonPath('organization.billing_currency', 'USD');

        // Still ONE org: the upsert stayed idempotent on the caller's own key even though the
        // key is no longer the primary key.
        $this->assertSame(1, Organization::query()->count());

        $organization = Organization::query()->firstOrFail();
        $this->assertSame('tenant_01', $organization->external_ref);
        $this->assertNotSame('tenant_01', $organization->id);
        $this->assertTrue(Str::isUlid($organization->id), 'The org id must be an opaque ULID.');
    }

    public function test_the_same_tenant_key_can_name_a_distinct_org_in_each_plane(): void
    {
        $auth = $this->operatorAuth();

        // The bug this replaces: the caller's key WAS the global primary key, so a tenant
        // provisioned in a sandbox could never be provisioned in production under the same
        // handle — the upsert probed inside the production plane, missed, inserted, and hit the
        // primary key, surfacing as an unhandled 500 on an endpoint documented as freely
        // repeatable. external_ref is unique per plane, which is what a sandbox is for.
        $this->putJson('/api/v1/organizations/tenant_shared', ['name' => 'Sandbox Acme'], $auth)
            ->assertCreated();

        $sandbox = Organization::query()->firstOrFail();
        $sandbox->forceFill(['environment' => 'sandbox', 'livemode' => false])->save();

        $this->putJson('/api/v1/organizations/tenant_shared', ['name' => 'Production Acme'], $auth)
            ->assertCreated();

        $refs = Organization::query()->withoutGlobalScopes()->where('external_ref', 'tenant_shared')->count();
        $this->assertSame(2, $refs, 'One tenant key must be free to name an org in each plane.');
    }

    public function test_an_org_scoped_token_cannot_provision_other_orgs(): void
    {
        Organization::query()->create(['id' => 'mine', 'name' => 'Mine']);
        ['plaintext' => $token] = ApiToken::issue('mine-sdk', 'mine');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->putJson('/api/v1/organizations/other', ['name' => 'Other'], $auth)->assertForbidden();
        $this->putJson('/api/v1/organizations/mine', ['name' => 'Mine Renamed'], $auth)->assertOk();
    }

    public function test_requires_authentication(): void
    {
        $this->putJson('/api/v1/organizations/tenant_01', ['name' => 'Acme'])->assertUnauthorized();
    }
}
