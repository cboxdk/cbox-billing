<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('organization.id', 'tenant_01')
            ->assertJsonPath('organization.billing_currency', 'USD');

        // Re-send with a changed name and a DIFFERENT currency: name updates, currency stays.
        $this->putJson('/api/v1/organizations/tenant_01', [
            'name' => 'Acme Support ApS',
            'billing_currency' => 'EUR',
        ], $auth)->assertOk()
            ->assertJsonPath('organization.name', 'Acme Support ApS')
            ->assertJsonPath('organization.billing_currency', 'USD');

        $this->assertSame(1, Organization::query()->count());
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
