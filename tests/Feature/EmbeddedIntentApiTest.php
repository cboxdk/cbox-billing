<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\GatewayCustomer;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VaultPaymentGateway;
use Tests\TestCase;

/**
 * The embedded-intent API (ADR-0009 Path B): setup/payment intents return a gateway client
 * secret a product confirms client-side; the org's gateway customer is minted once and
 * reused as the `account` on every intent; and the saved-method surface lists, defaults,
 * and removes cards. The gateway is a {@see VaultPaymentGateway} (the engine's fake plus a
 * coherent vault) so the whole server side runs without live keys.
 */
class EmbeddedIntentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function bindGateway(): VaultPaymentGateway
    {
        $gateway = new VaultPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    /** @return array{0: Organization, 1: array<string, string>} */
    private function orgWithToken(string $id): array
    {
        $organization = Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($id.'-sdk', $id);

        return [$organization, ['Authorization' => 'Bearer '.$token]];
    }

    public function test_setup_and_payment_intents_share_one_reused_gateway_customer(): void
    {
        $gateway = $this->bindGateway();
        [, $auth] = $this->orgWithToken('org_intent');

        // First intent for the org: save a card off-session.
        $setup = $this->postJson('/api/v1/setup-intents', ['org' => 'org_intent'], $auth);
        $setup->assertCreated()
            ->assertJsonPath('gateway', 'fake')
            ->assertJsonPath('publishable_key', 'pub_fake')
            ->assertJsonPath('status', 'succeeded');
        $this->assertStringStartsWith('cs_seti_', (string) $setup->json('client_secret'));

        // Second intent for the same org: an ad-hoc on-session charge.
        $payment = $this->postJson('/api/v1/payment-intents', [
            'org' => 'org_intent',
            'amount' => 5_000,
            'currency' => 'DKK',
        ], $auth);
        $payment->assertCreated()->assertJsonPath('gateway', 'fake');
        $this->assertStringStartsWith('cs_pi_', (string) $payment->json('client_secret'));

        // The gateway customer was created exactly once and stored.
        $this->assertSame(1, $gateway->customerCalls);
        $this->assertSame(1, GatewayCustomer::query()->where('organization_id', 'org_intent')->count());

        $stored = GatewayCustomer::query()->where('organization_id', 'org_intent')->firstOrFail();
        $this->assertSame('fake', $stored->gateway);
        $this->assertSame('cus_test_org_intent_1', $stored->gateway_customer_id);

        // Both intents were created against the SAME stored gateway customer id — never the org id.
        $this->assertSame($stored->gateway_customer_id, $gateway->setupIntents[0]->account);
        $this->assertSame($stored->gateway_customer_id, $gateway->paymentIntents[0]->account);
        $this->assertNotSame('org_intent', $gateway->paymentIntents[0]->account);
    }

    public function test_payment_intent_for_an_invoice_charges_its_total_and_carries_its_number(): void
    {
        $gateway = $this->bindGateway();
        [$organization, $auth] = $this->orgWithToken('org_invoice');

        $subscription = app(SubscribesOrganizations::class)
            ->subscribe($organization, Plan::query()->where('key', 'starter')->firstOrFail());
        $invoice = app(GeneratesInvoices::class)->generate($subscription->refresh());

        $response = $this->postJson('/api/v1/payment-intents', [
            'org' => 'org_invoice',
            'invoice' => $invoice->number,
        ], $auth);

        $response->assertCreated()->assertJsonPath('reference', $invoice->number);

        // The recorded request charges the invoice's own total and references its number,
        // so the settled webhook joins back to mark THIS invoice paid.
        $this->assertSame($invoice->total_minor, $gateway->paymentIntents[0]->amount->minor());
        $this->assertSame($invoice->currency, $gateway->paymentIntents[0]->amount->currency());
        $this->assertSame($invoice->number, $gateway->paymentIntents[0]->reference);
    }

    public function test_payment_intent_requires_an_invoice_or_an_amount(): void
    {
        $this->bindGateway();
        [, $auth] = $this->orgWithToken('org_bad');

        $this->postJson('/api/v1/payment-intents', ['org' => 'org_bad'], $auth)
            ->assertStatus(422);
    }

    public function test_payment_methods_list_default_and_remove(): void
    {
        $gateway = $this->bindGateway();
        [$organization, $auth] = $this->orgWithToken('org_pm');

        // Seed the vault under the org's resolved gateway customer (what a confirmed
        // SetupIntent would vault via the gateway's webhook).
        $account = app(ResolvesGatewayCustomer::class)->resolve($organization);
        $gateway->attachPaymentMethod($account, 'pm_one');
        $gateway->attachPaymentMethod($account, 'pm_two');

        // List: two methods, the first attached is the default.
        $list = $this->getJson('/api/v1/payment-methods/org_pm', $auth);
        $list->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'pm_one')
            ->assertJsonPath('data.0.brand', 'visa')
            ->assertJsonPath('data.0.last4', '4242')
            ->assertJsonPath('data.0.exp_month', 12)
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.is_default', false);

        // Default: promote the second method.
        $this->postJson('/api/v1/payment-methods/org_pm/default', ['id' => 'pm_two'], $auth)
            ->assertOk()
            ->assertJsonPath('data.0.is_default', false)
            ->assertJsonPath('data.1.id', 'pm_two')
            ->assertJsonPath('data.1.is_default', true);

        // Remove: detach the first method.
        $this->deleteJson('/api/v1/payment-methods/org_pm/pm_one', [], $auth)
            ->assertNoContent();

        $this->getJson('/api/v1/payment-methods/org_pm', $auth)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'pm_two');
    }

    public function test_embedded_intent_api_enforces_per_org_scope(): void
    {
        $this->bindGateway();

        Organization::query()->create(['id' => 'org_a', 'name' => 'A', 'billing_country' => 'DK']);
        Organization::query()->create(['id' => 'org_b', 'name' => 'B', 'billing_country' => 'DK']);

        ['plaintext' => $token] = ApiToken::issue('a-sdk', 'org_a');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/setup-intents', ['org' => 'org_b'], $auth)->assertForbidden();
        $this->postJson('/api/v1/payment-intents', ['org' => 'org_b', 'amount' => 100, 'currency' => 'DKK'], $auth)->assertForbidden();
        $this->getJson('/api/v1/payment-methods/org_b', $auth)->assertForbidden();
        $this->postJson('/api/v1/payment-methods/org_b/default', ['id' => 'pm_x'], $auth)->assertForbidden();
        $this->deleteJson('/api/v1/payment-methods/org_b/pm_x', [], $auth)->assertForbidden();

        // Deny-by-default: no gateway customer was ever minted for the off-limits org.
        $this->assertSame(0, GatewayCustomer::query()->where('organization_id', 'org_b')->count());
    }

    public function test_embedded_intent_api_denies_unauthenticated_requests(): void
    {
        $this->postJson('/api/v1/setup-intents', ['org' => 'org_x'])->assertUnauthorized();
        $this->postJson('/api/v1/payment-intents', ['org' => 'org_x', 'amount' => 100, 'currency' => 'DKK'])->assertUnauthorized();
        $this->getJson('/api/v1/payment-methods/org_x')->assertUnauthorized();
    }
}
