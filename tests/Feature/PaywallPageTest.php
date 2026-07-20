<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingSession;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hosted paywall page (#57): it renders the UpgradeGate's required plan + hosted-checkout
 * deep-link for a gated FEATURE and for a gated metered LIMIT (reusing the gate, not recomputing
 * it), and states the honest "no upgrade path" outcome when the org already has the capability.
 */
class PaywallPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    public function test_paywall_renders_required_plan_and_checkout_url_for_a_gated_feature(): void
    {
        // org_klarhed is on Starter, which does not grant SSO; the cheapest granting plan is Team.
        $response = $this->get('/paywall?'.http_build_query(['org' => 'org_klarhed', 'feature' => 'sso']))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Single sign-on', $html);
        $this->assertStringContainsString('Upgrade to Team', $html);

        // The CTA is the gate's own hosted-checkout deep-link, resolving to a real Team session.
        $this->assertMatchesRegularExpression('#href="[^"]*/billing/checkout/[^"]+"#', $html);
        preg_match('#/billing/checkout/([A-Za-z0-9]+)#', $html, $m);
        $session = BillingSession::query()->where('token_hash', BillingSession::hashToken($m[1]))->firstOrFail();
        $this->assertSame('team', $session->plan_key);
    }

    public function test_paywall_renders_required_plan_for_a_gated_metered_limit(): void
    {
        // Starter has the events.ingested meter disabled; Team is the cheapest plan that enables it.
        $response = $this->get('/paywall?'.http_build_query(['org' => 'org_klarhed', 'meter' => 'events.ingested']))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Ingested events', $html);
        $this->assertStringContainsString('Upgrade to Team', $html);
        $this->assertStringContainsString('/billing/checkout/', $html);
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
