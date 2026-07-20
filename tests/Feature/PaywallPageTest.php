<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hosted paywall page (#57): it renders the UpgradeGate's required plan for a gated FEATURE
 * and for a gated metered LIMIT, and states the honest "no upgrade path" outcome when the org
 * already has the capability.
 *
 * It is PUBLIC and unauthenticated, so it is side-effect-free: it never mints a BillingSession
 * for the query's arbitrary org (no cross-tenant existence disclosure, no unbounded rows), and
 * its caller-supplied `return_url` is allow-listed to the seller's known hosts (no open redirect).
 */
class PaywallPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        // A deterministic allow-listed origin for the return_url tests.
        config(['app.url' => 'https://seller.example']);
    }

    public function test_paywall_renders_the_required_plan_for_a_gated_feature_without_minting_a_session(): void
    {
        // org_klarhed is on Starter, which does not grant SSO; the cheapest granting plan is Team.
        $response = $this->get('/paywall?'.http_build_query(['org' => 'org_klarhed', 'feature' => 'sso']))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Single sign-on', $html);
        $this->assertStringContainsString('Team', $html);

        // The public page never mints a hosted-checkout session (no deep-link, no row).
        $this->assertStringNotContainsString('/billing/checkout/', $html);
        $this->assertSame(0, BillingSession::query()->count());
    }

    public function test_paywall_renders_the_required_plan_for_a_gated_metered_limit_without_minting_a_session(): void
    {
        // Starter has the events.ingested meter disabled; Team is the cheapest plan that enables it.
        $response = $this->get('/paywall?'.http_build_query(['org' => 'org_klarhed', 'meter' => 'events.ingested']))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Ingested events', $html);
        $this->assertStringContainsString('Team', $html);
        $this->assertStringNotContainsString('/billing/checkout/', $html);
        $this->assertSame(0, BillingSession::query()->count());
    }

    public function test_paywall_deep_links_only_to_an_existing_supplied_session_never_a_minted_one(): void
    {
        // A checkout session the caller already holds (minted through an authorized path) is
        // linked as the CTA; the public page itself still mints nothing new.
        $sessions = app(ManagesBillingSessions::class);
        $org = Organization::query()->findOrFail('org_klarhed');
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $session = $sessions->openCheckout($org, $team, null, 'https://seller.example/done');
        $token = (string) $session->token;

        $before = BillingSession::query()->count();

        $html = (string) $this->get('/paywall?'.http_build_query([
            'org' => 'org_klarhed', 'feature' => 'sso', 'session' => $token,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('/billing/checkout/'.$token, $html);
        // No NEW session was created by rendering the public page.
        $this->assertSame($before, BillingSession::query()->count());
    }

    public function test_paywall_ignores_an_unknown_session_token_and_mints_nothing(): void
    {
        $html = (string) $this->get('/paywall?'.http_build_query([
            'org' => 'org_klarhed', 'feature' => 'sso', 'session' => 'not-a-real-session-token',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('/billing/checkout/', $html);
        $this->assertSame(0, BillingSession::query()->count());
    }

    public function test_paywall_accepts_a_same_domain_return_url(): void
    {
        $this->get('/paywall?'.http_build_query([
            'org' => 'org_klarhed',
            'feature' => 'sso',
            'return_url' => 'https://seller.example/back-to-app',
        ]))->assertOk()->assertSee('https://seller.example/back-to-app', false);
    }

    public function test_paywall_rejects_an_off_domain_return_url(): void
    {
        $this->get('/paywall?'.http_build_query([
            'org' => 'org_klarhed',
            'feature' => 'sso',
            'return_url' => 'https://evil.example/phish',
        ]))->assertSessionHasErrors('return_url');
    }

    public function test_paywall_states_no_path_when_the_org_already_has_the_capability(): void
    {
        // org_fjord is on Scale, which already grants SSO — the gate offers no upgrade.
        $html = (string) $this->get('/paywall?'.http_build_query(['org' => 'org_fjord', 'feature' => 'sso']))->assertOk()->getContent();

        $this->assertStringNotContainsString('/billing/checkout/', $html);
        $this->assertStringContainsString('isn’t available on any plan', $html);
    }

    public function test_paywall_requires_an_org(): void
    {
        $this->get('/paywall?feature=sso')->assertSessionHasErrors('org');
    }
}
