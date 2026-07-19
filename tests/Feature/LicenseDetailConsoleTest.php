<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use Cbox\License\Support\Ed25519KeyPair;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LicensingSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-license detail page (Wave 4): it renders a minted license's decoded contents —
 * entitlements, limits, binding, window — plus its issue/renew history, and reflects a
 * revocation. Proven against a real minted artifact.
 */
class LicenseDetailConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = Ed25519KeyPair::generate();
        config([
            'billing.licensing.signing_key' => $keyPair['privateKey'],
            'billing.licensing.public_key' => $keyPair['publicKey'],
        ]);

        $this->seed([CatalogSeeder::class, LicensingSeeder::class, OrganizationSeeder::class]);
    }

    public function test_the_detail_page_renders_entitlements_history_and_revocation(): void
    {
        $license = app(IssuesLicenses::class)->issue(customerId: 'org_hverdag', planId: 'team-onprem');

        // The detail renders the decoded contents + an issued history row.
        $this->withSession($this->session)->get('/licenses/'.$license->id)
            ->assertOk()
            ->assertSee($license->id)
            ->assertSee('Entitlements')
            ->assertSee('Limits')
            ->assertSee('issued');

        // A renewal adds a history row; both licenses are findable by their own id.
        $renewed = app(IssuesLicenses::class)->renew($license->id, null);
        $this->withSession($this->session)->get('/licenses/'.$renewed->id)
            ->assertOk()
            ->assertSee('renewed');

        // Revocation surfaces on the page.
        app(IssuesLicenses::class)->revoke($renewed->id, 'Test revoke');
        $this->withSession($this->session)->get('/licenses/'.$renewed->id)
            ->assertOk()
            ->assertSee('revoked')
            ->assertSee('Test revoke');
    }

    public function test_an_unknown_license_is_404(): void
    {
        $this->withSession($this->session)->get('/licenses/lic_nope')->assertNotFound();
    }
}
