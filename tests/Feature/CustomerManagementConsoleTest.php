<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Models\CboxIdAccessGrant;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VaultPaymentGateway;
use Tests\TestCase;

/**
 * Operator organization management (Wave 4): suspend/reactivate flips the app mirror AND the
 * engine standing; a profile edit persists but refuses a currency change once the account has
 * transacted; the event log aggregates the org's real records; a vaulted payment method is
 * removed through the gateway detach; and the access-grant mirror renders read-only.
 */
class CustomerManagementConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    private function org(string $id = 'org_c', ?string $currency = null): Organization
    {
        return Organization::query()->create([
            'id' => $id, 'name' => 'Customer Co', 'billing_email' => 'c@example.test',
            'billing_country' => 'DK', 'billing_currency' => $currency,
        ]);
    }

    public function test_suspend_and_reactivate_toggle_the_mirror_and_standing(): void
    {
        $org = $this->org();
        $standing = app(AccountStanding::class);

        $this->withSession($this->session)->post('/customers/'.$org->id.'/suspend')
            ->assertRedirect('/customers/'.$org->id)->assertSessionHas('status');

        $this->assertNotNull($org->fresh()?->suspended_at);
        $this->assertSame(AccountStandingState::Suspended, $standing->standingOf($org->id));

        $this->withSession($this->session)->post('/customers/'.$org->id.'/reactivate')
            ->assertRedirect('/customers/'.$org->id)->assertSessionHas('status');

        $this->assertNull($org->fresh()?->suspended_at);
        $this->assertSame(AccountStandingState::Good, $standing->standingOf($org->id));
    }

    public function test_the_profile_edit_persists(): void
    {
        $org = $this->org(currency: 'DKK');

        $this->withSession($this->session)->put('/customers/'.$org->id, [
            'name' => 'Renamed Co', 'billing_email' => 'new@example.test', 'tax_id' => 'DK12345678', 'billing_currency' => 'DKK',
        ])->assertRedirect('/customers/'.$org->id)->assertSessionHas('status');

        $fresh = $org->fresh();
        $this->assertSame('Renamed Co', $fresh?->name);
        $this->assertSame('new@example.test', $fresh?->billing_email);
        $this->assertSame('DK12345678', $fresh?->tax_id);
    }

    public function test_a_currency_change_is_refused_once_the_account_has_transacted(): void
    {
        $org = $this->org(currency: 'DKK');
        Invoice::query()->create(['organization_id' => $org->id, 'seller' => 'cbox-dk', 'number' => 'CBOX-DK-9001', 'currency' => 'DKK', 'status' => 'paid']);

        $this->withSession($this->session)->put('/customers/'.$org->id, [
            'name' => 'Customer Co', 'billing_currency' => 'EUR',
        ])->assertRedirect()->assertSessionHas('error');

        // The lock held — the currency is untouched.
        $this->assertSame('DKK', $org->fresh()?->billing_currency);
    }

    public function test_the_event_log_aggregates_real_records(): void
    {
        $org = $this->org(currency: 'DKK');
        Invoice::query()->create(['organization_id' => $org->id, 'seller' => 'cbox-dk', 'number' => 'CBOX-DK-9100', 'currency' => 'DKK', 'status' => 'paid', 'issued_at' => now()]);

        $this->withSession($this->session)->get('/customers/'.$org->id)
            ->assertOk()
            ->assertSee('Activity log')
            ->assertSee('CBOX-DK-9100');
    }

    public function test_a_vaulted_payment_method_is_removed_through_the_gateway(): void
    {
        $gateway = new VaultPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $org = $this->org('org_pm');
        // Resolve the gateway customer (creates the mapping), then vault a method under it.
        $account = app(ResolvesGatewayCustomer::class)->resolve($org);
        $gateway->attachPaymentMethod($account, 'pm_1');
        $this->assertNotEmpty($gateway->paymentMethods($account));

        $this->withSession($this->session)->get('/customers/'.$org->id)
            ->assertOk()->assertSee('Payment methods')->assertSee('Remove');

        $this->withSession($this->session)->post('/customers/'.$org->id.'/payment-methods/remove', ['id' => 'pm_1'])
            ->assertRedirect()->assertSessionHas('status');

        // The gateway detach ran — the vault no longer holds the method.
        $this->assertEmpty($gateway->paymentMethods($account));
    }

    public function test_the_access_grant_mirror_lists_and_renders_on_the_customer(): void
    {
        $org = $this->org('org_g');
        CboxIdAccessGrant::query()->create(['organization_id' => $org->id, 'subject' => 'user|alice', 'role' => 'billing-admin', 'environment_key' => 'default']);
        CboxIdAccessGrant::query()->create(['organization_id' => $org->id, 'subject' => 'user|bob', 'role' => CboxIdAccessGrant::NO_ROLE]);

        // The standalone viewer.
        $this->withSession($this->session)->get('/access-grants')
            ->assertOk()
            ->assertSee('user|alice')
            ->assertSee('billing-admin')
            ->assertSee('membership');

        // The cross-linked panel on the customer.
        $this->withSession($this->session)->get('/customers/'.$org->id)
            ->assertOk()
            ->assertSee('Access &amp; roles', false)
            ->assertSee('user|bob');
    }
}
