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

        $response = $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        // Only the digest is stored, so recover the plaintext token from the returned URL.
        $sessionToken = basename((string) parse_url((string) $response->json('url'), PHP_URL_PATH));
        $session = BillingSession::query()->where('organization_id', $org)->where('type', 'portal')->firstOrFail();
        $session->token = $sessionToken;

        return $session;
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
        // The preview also carries a server-preformatted amount string (single money seam),
        // so the client never re-derives it with a hardcoded /100 + locale.
        $preview->assertJsonPath('new_recurring', 'DKK 1.240,00');
        $this->assertIsString($preview->json('due_now'));

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

    public function test_the_portal_lists_multiple_methods_and_removes_one(): void
    {
        $gateway = new FakePaymentGateway(PaymentResult::succeeded('gw_ref'));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->subscribedOrg('org_methods');
        $gateway->attachPaymentMethod('org_methods', 'pm_a');
        $gateway->attachPaymentMethod('org_methods', 'pm_b');

        // The portal lists the vaulted methods with the remove control.
        $this->get('/billing/portal/'.$this->portalSession('org_methods')->token)
            ->assertOk()
            ->assertSee('Payment methods')
            ->assertSee('data-pm-remove', false);

        // Set-default returns the refreshed list, then remove detaches through the gateway.
        $session = $this->portalSession('org_methods');
        $this->postJson('/billing/portal/'.$session->token.'/payment-method/default', ['payment_method' => 'pm_a'])
            ->assertOk()->assertJsonPath('methods.0.id', 'pm_a');

        $this->postJson('/billing/portal/'.$session->token.'/payment-method/remove', ['payment_method' => 'pm_a'])
            ->assertOk();

        $remaining = array_map(static fn ($m): string => $m->id, $gateway->paymentMethods('org_methods'));
        $this->assertNotContains('pm_a', $remaining);
        $this->assertContains('pm_b', $remaining);
    }

    public function test_a_portal_token_cannot_detach_another_orgs_payment_method(): void
    {
        $gateway = new FakePaymentGateway(PaymentResult::succeeded('gw_ref'));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->subscribedOrg('org_own');
        $gateway->attachPaymentMethod('org_own', 'pm_own');
        // Another org's vaulted method — the gateway would detach it globally by id.
        $gateway->attachPaymentMethod('org_foreign', 'pm_foreign');

        $session = $this->portalSession('org_own');

        // org_own's portal token asks to remove org_foreign's method → deny-by-default 404.
        $this->postJson('/billing/portal/'.$session->token.'/payment-method/remove', ['payment_method' => 'pm_foreign'])
            ->assertNotFound();

        // The foreign method survives; the cross-tenant detach never reached the gateway.
        $foreign = array_map(static fn ($m): string => $m->id, $gateway->paymentMethods('org_foreign'));
        $this->assertContains('pm_foreign', $foreign);
    }
}
