<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\DatabaseIssuedLicenseStore;
use App\Billing\Licensing\DatabaseRevocationRegistry;
use App\Billing\Licensing\Exceptions\LicensingException;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\License\Capabilities;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\Ed25519LicenseVerifier;
use Cbox\License\Enums\LicenseStatus;
use Cbox\License\Support\Ed25519KeyPair;
use Cbox\License\ValueObjects\LicenseLimits;
use Cbox\License\ValueObjects\LicenseRequest;
use Cbox\License\ValueObjects\RevocationList;
use Cbox\License\ValueObjects\VerificationContext;
use Database\Seeders\LicensingSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The on-prem licensing round-trip, proven against a REAL Ed25519 keypair: what billing
 * mints here must verify exactly as the self-hosted deployment will read it. A pair is
 * generated per test and set as the issuer config keys; every assertion runs the minted
 * artifact through the real {@see Ed25519LicenseVerifier} with the matching public key.
 */
class LicensingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{publicKey: string, privateKey: string} */
    private array $keyPair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPair = Ed25519KeyPair::generate();

        config([
            'billing.licensing.signing_key' => $this->keyPair['privateKey'],
            'billing.licensing.public_key' => $this->keyPair['publicKey'],
        ]);

        $this->seed(LicensingSeeder::class);
    }

    // --- Keygen command ---------------------------------------------------------------

    public function test_keygen_command_outputs_a_usable_pair(): void
    {
        $exit = $this->artisan('billing:license-keygen')
            ->expectsOutputToContain('CBOX_LICENSE_SIGNING_KEY')
            ->expectsOutputToContain('CBOX_LICENSE_PUBLIC_KEY')
            ->run();

        $this->assertSame(0, $exit);

        // The two printed base64 keys must actually sign + verify a token end to end.
        Artisan::call('billing:license-keygen');
        preg_match_all('/^ {2,}([A-Za-z0-9+\/=]{40,})$/m', Artisan::output(), $matches);

        $this->assertCount(2, $matches[1]);
        [$private, $public] = strlen($matches[1][0]) > strlen($matches[1][1])
            ? [$matches[1][0], $matches[1][1]]
            : [$matches[1][1], $matches[1][0]];

        $now = new DateTimeImmutable('2026-07-16T12:00:00Z');
        $key = (new Ed25519LicenseIssuer($private))->issue(new LicenseRequest(
            plan: 'enterprise-onprem',
            entitlements: [Capabilities::SSO],
            limits: new LicenseLimits(organizations: 1),
            customerId: 'org_x',
            deploymentId: 'dep_x',
            licensedDomain: null,
            issuedAt: $now,
            notBefore: $now,
            expiresAt: $now->modify('+1 year'),
        ));

        $result = (new Ed25519LicenseVerifier($public))->verify($key, new VerificationContext('dep_x', null, $now));
        $this->assertTrue($result->isLicensed());
    }

    // --- Issue via the service --------------------------------------------------------

    public function test_service_issue_yields_a_key_that_verifies_and_grants_the_profile(): void
    {
        $this->org('org_svc');

        $license = app(IssuesLicenses::class)->issue(
            customerId: 'org_svc',
            planId: 'enterprise-onprem',
            deploymentId: 'dep_svc',
        );

        $result = $this->verifier()->verify(
            $license->key,
            new VerificationContext('dep_svc', null, Carbon::now()->toDateTimeImmutable()),
        );

        $this->assertTrue($result->isLicensed());
        $this->assertSame(LicenseStatus::Valid, $result->status);

        // Grants EXACTLY the profile's entitlements + limits.
        $this->assertSame([
            Capabilities::MULTI_TENANT_PLATFORM,
            Capabilities::SSO,
            Capabilities::SAML,
            Capabilities::SCIM,
            Capabilities::ANALYTICS,
            Capabilities::COMPLIANCE,
            Capabilities::SUPPORT,
        ], $result->entitlements());

        $this->assertSame(
            ['organizations' => 50, 'seats' => 500, 'environments' => 5],
            $result->limits()?->toArray(),
        );

        // Deployment binding: the same key against a DIFFERENT deployment is refused.
        $mismatch = $this->verifier()->verify(
            $license->key,
            new VerificationContext('dep_other', null, Carbon::now()->toDateTimeImmutable()),
        );
        $this->assertSame(LicenseStatus::BindingMismatch, $mismatch->status);
    }

    public function test_non_licensable_plan_cannot_be_issued(): void
    {
        $this->org('org_deny');

        $this->expectException(LicensingException::class);

        app(IssuesLicenses::class)->issue(customerId: 'org_deny', planId: 'starter');
    }

    // --- Issue via the API ------------------------------------------------------------

    public function test_api_issue_returns_a_verifiable_key(): void
    {
        $this->org('org_api');
        $auth = $this->operatorToken();

        $response = $this->postJson('/api/v1/licenses', [
            'customer_id' => 'org_api',
            'plan' => 'enterprise-onprem',
            'deployment_id' => 'dep_api',
        ], $auth);

        $response->assertCreated()
            ->assertJsonPath('plan', 'enterprise-onprem')
            ->assertJsonPath('deployment_id', 'dep_api')
            ->assertJsonPath('public_key', $this->keyPair['publicKey']);

        $key = $response->json('key');
        $this->assertIsString($key);

        $result = $this->verifier()->verify(
            $key,
            new VerificationContext('dep_api', null, Carbon::now()->toDateTimeImmutable()),
        );
        $this->assertTrue($result->isLicensed());
        $this->assertContains(Capabilities::SAML, $result->entitlements());
    }

    public function test_api_rejects_a_non_licensable_plan(): void
    {
        $this->org('org_api_deny');

        $this->postJson('/api/v1/licenses', [
            'customer_id' => 'org_api_deny',
            'plan' => 'starter',
        ], $this->operatorToken())->assertStatus(422);
    }

    public function test_api_license_management_requires_an_operator_token(): void
    {
        Organization::query()->create(['id' => 'org_scoped', 'name' => 'Scoped', 'billing_country' => 'DK']);
        ['plaintext' => $token] = ApiToken::issue('scoped-sdk', 'org_scoped');

        $this->postJson('/api/v1/licenses', [
            'customer_id' => 'org_scoped',
            'plan' => 'enterprise-onprem',
        ], ['Authorization' => 'Bearer '.$token])->assertForbidden();

        $this->postJson('/api/v1/licenses', ['customer_id' => 'x', 'plan' => 'y'])->assertUnauthorized();
    }

    // --- Renew ------------------------------------------------------------------------

    public function test_renew_extends_the_expiry_under_a_fresh_id(): void
    {
        $this->org('org_renew');
        $auth = $this->operatorToken();

        $issue = $this->postJson('/api/v1/licenses', [
            'customer_id' => 'org_renew',
            'plan' => 'team-onprem',
            'deployment_id' => 'dep_renew',
        ], $auth)->assertCreated();

        $originalId = $issue->json('id');
        $originalExpiry = new DateTimeImmutable((string) $issue->json('expires_at'));

        $renew = $this->postJson('/api/v1/licenses/'.$originalId.'/renew', [], $auth)->assertOk();

        $renewedExpiry = new DateTimeImmutable((string) $renew->json('expires_at'));

        $this->assertNotSame($originalId, $renew->json('id'));
        $this->assertGreaterThan($originalExpiry->getTimestamp(), $renewedExpiry->getTimestamp());

        // Same deployment: activation now serves the renewed license.
        $activate = $this->getJson('/api/v1/license/activate?deployment_id=dep_renew')->assertOk();
        $this->assertSame($renew->json('id'), $activate->json('license_id'));
    }

    // --- Revoke + activation ----------------------------------------------------------

    public function test_revoke_makes_the_verifier_report_revoked_via_activation(): void
    {
        $this->org('org_revoke');
        $auth = $this->operatorToken();

        $issue = $this->postJson('/api/v1/licenses', [
            'customer_id' => 'org_revoke',
            'plan' => 'enterprise-onprem',
            'deployment_id' => 'dep_revoke',
        ], $auth)->assertCreated();

        $licenseId = (string) $issue->json('id');
        $licenseKey = (string) $issue->json('key');

        // Before revocation the license verifies clean.
        $before = $this->verifier()->verify(
            $licenseKey,
            new VerificationContext('dep_revoke', null, Carbon::now()->toDateTimeImmutable()),
        );
        $this->assertTrue($before->isLicensed());

        $this->postJson('/api/v1/licenses/'.$licenseId.'/revoke', ['reason' => 'test'], $auth)
            ->assertOk()
            ->assertJsonPath('revoked', true);

        // The activation heartbeat hands back the signed revocation list + the license.
        $activate = $this->getJson('/api/v1/license/activate?deployment_id=dep_revoke')->assertOk();

        $revocations = RevocationList::fromSigned((string) $activate->json('revocation_list'), $this->keyPair['publicKey']);
        $this->assertNotNull($revocations);

        $result = $this->verifier()->verify(
            (string) $activate->json('license_key'),
            new VerificationContext('dep_revoke', null, Carbon::now()->toDateTimeImmutable(), $revocations),
        );

        $this->assertSame(LicenseStatus::Revoked, $result->status);
    }

    public function test_activation_is_generic_not_found_for_an_unknown_deployment(): void
    {
        $this->getJson('/api/v1/license/activate?deployment_id=dep_nope')->assertNotFound();
        $this->getJson('/api/v1/license/activate')->assertStatus(422);
    }

    // --- Persistence round-trip -------------------------------------------------------

    public function test_database_stores_round_trip(): void
    {
        $store = app(DatabaseIssuedLicenseStore::class);
        $now = new DateTimeImmutable('2026-07-16T00:00:00Z');

        $license = new IssuedLicense(
            id: 'lic_test123',
            key: 'signed.jwt.artifact',
            customerId: 'org_persist',
            deploymentId: 'dep_persist',
            plan: 'enterprise-onprem',
            entitlements: [Capabilities::SSO, Capabilities::SAML],
            limits: new LicenseLimits(organizations: 10, seats: 100, environments: 2),
            issuedAt: $now,
            notBefore: $now,
            expiresAt: $now->modify('+1 year'),
            licensedDomain: 'id.example.com',
        );

        $store->save($license);

        $found = $store->find('lic_test123');
        $this->assertNotNull($found);
        $this->assertSame('org_persist', $found->customerId);
        $this->assertSame('dep_persist', $found->deploymentId);
        $this->assertSame([Capabilities::SSO, Capabilities::SAML], $found->entitlements);
        $this->assertSame(['organizations' => 10, 'seats' => 100, 'environments' => 2], $found->limits->toArray());
        $this->assertSame('id.example.com', $found->licensedDomain);
        $this->assertSame($license->expiresAt->getTimestamp(), $found->expiresAt->getTimestamp());

        $this->assertSame('lic_test123', $store->forDeployment('dep_persist')?->id);
        $this->assertCount(1, $store->forCustomer('org_persist'));

        $registry = app(DatabaseRevocationRegistry::class);
        $registry->revoke('lic_test123', 'a reason');
        $registry->revoke('lic_test123'); // idempotent
        $this->assertTrue($registry->isRevoked('lic_test123'));
        $this->assertSame(['lic_test123'], $registry->revokedIds());
    }

    // --- Console screens --------------------------------------------------------------

    public function test_console_screens_render_and_issue(): void
    {
        $this->org('org_console');
        $this->withSession(['auth.user' => [
            'sub' => 'demo|tester', 'name' => 'Op', 'email' => 'op@example.test', 'org' => 'Cbox', 'picture' => null,
        ]]);

        $this->get('/licenses')->assertOk()->assertSee('Issue a license');
        $this->get('/licenses/distribution')->assertOk()->assertSee($this->keyPair['publicKey']);

        $this->post('/licenses', [
            'customer_id' => 'org_console',
            'plan' => 'enterprise-onprem',
        ])->assertRedirect(route('billing.licenses'))->assertSessionHas('issued_license');

        $this->assertCount(1, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_console'));
    }

    // --- Lifecycle command ------------------------------------------------------------

    public function test_issue_licenses_command_is_idempotent_per_deployment(): void
    {
        $organization = $this->org('org_cmd');
        app(SubscribesOrganizations::class)->subscribe(
            $organization,
            Plan::query()->where('key', 'enterprise-onprem')->firstOrFail(),
        );

        $this->artisan('billing:issue-licenses')->assertSuccessful();
        $this->assertCount(1, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_cmd'));
        $this->assertNotNull(app(DatabaseIssuedLicenseStore::class)->forDeployment('dep_org_cmd'));

        // A second run is a no-op — one active license per deployment.
        $this->artisan('billing:issue-licenses')->assertSuccessful();
        $this->assertCount(1, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_cmd'));
    }

    // --- helpers ----------------------------------------------------------------------

    private function org(string $id): Organization
    {
        return Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);
    }

    /** @return array<string, string> */
    private function operatorToken(): array
    {
        ['plaintext' => $token] = ApiToken::issue('ops', null);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function verifier(): Ed25519LicenseVerifier
    {
        return new Ed25519LicenseVerifier($this->keyPair['publicKey']);
    }
}
