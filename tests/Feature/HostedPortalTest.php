<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hosted customer portal (ADR-0009 Path A), authorized solely by the session token: it
 * renders the current subscription, previews and applies a plan change, cancels, and
 * updates the payment method by attaching the method the gateway vaulted from a SetupIntent
 * — every write flowing through the SAME lifecycle services the management API drives.
 */
class HostedPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function subscribedOrg(string $id, string $plan = 'starter'): Organization
    {
        $organization = Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);

        app(SubscribesOrganizations::class)->subscribe($organization, Plan::query()->where('key', $plan)->firstOrFail());

        return $organization;
    }

    private function portalSession(string $org): BillingSession
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        return BillingSession::query()->where('organization_id', $org)->where('type', 'portal')->firstOrFail();
    }

    public function test_the_portal_page_renders_the_current_subscription(): void
    {
        $this->subscribedOrg('org_portal');
        $session = $this->portalSession('org_portal');

        $this->get('/billing/portal/'.$session->token)
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('Change plan');
    }

    public function test_the_portal_page_404s_for_an_invalid_token(): void
    {
        $this->get('/billing/portal/not-a-real-token')->assertNotFound();
    }

    public function test_the_portal_previews_and_applies_a_plan_change(): void
    {
        $this->subscribedOrg('org_change');
        $session = $this->portalSession('org_change');

        $preview = $this->postJson('/billing/portal/'.$session->token.'/preview', ['plan' => 'team']);
        $preview->assertOk()->assertJsonPath('new_recurring_minor', 124_000)->assertJsonPath('currency', 'DKK');
        $this->assertGreaterThan(0, $preview->json('due_now_minor'));

        // Preview does not mutate.
        $this->assertSame('starter', Subscription::query()->where('organization_id', 'org_change')->firstOrFail()->plan->key);

        $this->postJson('/billing/portal/'.$session->token.'/change', ['plan' => 'team'])
            ->assertOk()
            ->assertJsonPath('new_recurring_minor', 124_000);

        $this->assertSame('team', Subscription::query()->where('organization_id', 'org_change')->firstOrFail()->refresh()->plan->key);
    }

    public function test_the_portal_cancels_at_period_end(): void
    {
        $this->subscribedOrg('org_cancel');
        $session = $this->portalSession('org_cancel');

        $this->postJson('/billing/portal/'.$session->token.'/cancel', ['at_period_end' => true])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('renews_at', null);

        $this->assertTrue(Subscription::query()->where('organization_id', 'org_cancel')->firstOrFail()->cancel_at_period_end);
    }

    public function test_the_portal_updates_the_payment_method_via_a_setup_intent(): void
    {
        $gateway = new FakePaymentGateway(PaymentResult::succeeded('gw_ref'));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->subscribedOrg('org_pm');
        $session = $this->portalSession('org_pm');

        // Create the SetupIntent the element confirms against (no charge).
        $this->postJson('/billing/portal/'.$session->token.'/setup-intent')
            ->assertOk()
            ->assertJsonPath('gateway', 'fake');

        // Attach the method the gateway vaulted, and make it the default.
        $this->postJson('/billing/portal/'.$session->token.'/payment-method', ['payment_method' => 'pm_new'])
            ->assertOk()
            ->assertJsonPath('method.id', 'pm_new')
            ->assertJsonPath('method.default', true);

        $this->assertNotEmpty($gateway->paymentMethods('org_pm'));
    }
}
