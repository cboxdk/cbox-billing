<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\DatabaseBillingCurrencyLock;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    public function test_engine_stores_bind_to_durable_implementations(): void
    {
        $this->assertInstanceOf(DatabaseLedger::class, app(Ledger::class));
        $this->assertInstanceOf(DatabaseEventLog::class, app(EventLog::class));
        $this->assertInstanceOf(DatabaseCheckpointStore::class, app(CheckpointStore::class));
        $this->assertInstanceOf(DatabaseBillingCurrencyLock::class, app(BillingCurrencyLock::class));
    }

    public function test_meter_policy_resolves_from_active_subscription_plan(): void
    {
        $resolver = app(MeterPolicyResolver::class);

        $policy = $resolver->resolve('org_hverdag', 'api.requests');

        $this->assertNotNull($policy);
        $this->assertTrue($policy->enabled);
        $this->assertSame(1_000_000, $policy->allowance);
    }

    public function test_meter_policy_is_deny_by_default_for_unknown_org(): void
    {
        $this->assertNull(app(MeterPolicyResolver::class)->resolve('org_unknown', 'api.requests'));
    }

    public function test_scale_plan_grants_unlimited_dimensions(): void
    {
        $policy = app(MeterPolicyResolver::class)->resolve('org_fjord', 'api.requests');

        $this->assertNotNull($policy);
        $this->assertTrue($policy->unlimited);
    }

    public function test_expected_entitlements_are_derived_from_the_catalog(): void
    {
        $targets = iterator_to_array(app(ExpectedEntitlements::class)->targets());

        // One target per active, non-paused-subscription org in the seeded book. The oracle
        // filters on `status = active` and no pause, so the canceled, trialing, past-due and
        // paused orgs are excluded — leaving Hverdag, Klarhed, Fjord and (Active,
        // cancel-at-period-end) Vinter.
        $this->assertCount(4, $targets);

        $hverdag = collect($targets)->firstWhere('org', 'org_hverdag');
        $this->assertNotNull($hverdag);
        $this->assertSame('team', $hverdag->plan);
        $this->assertContains('api.requests', $hverdag->expectedKeys);
    }

    public function test_invoice_payment_applier_marks_the_app_invoice_paid(): void
    {
        $invoice = Invoice::query()->where('status', 'open')->firstOrFail();

        app(InvoicePaymentApplier::class)->markPaid(
            $invoice->number,
            Money::ofMinor($invoice->total_minor, $invoice->currency),
            PaymentResult::succeeded('pi_test_123'),
        );

        $invoice->refresh();
        $this->assertTrue($invoice->isPaid());
        $this->assertSame('pi_test_123', $invoice->gateway_reference);
    }

    public function test_durable_account_standing_persists_a_flag(): void
    {
        $standing = app(AccountStanding::class);

        $this->assertSame(AccountStandingState::Good, $standing->standingOf('org_hverdag'));

        $standing->flag('org_hverdag', AccountStandingState::Disputed, 'chargeback ch_1');

        $this->assertSame(AccountStandingState::Disputed, $standing->standingOf('org_hverdag'));
        $this->assertDatabaseHas('account_standings', [
            'account' => 'org_hverdag',
            'state' => 'disputed',
        ]);
    }
}
