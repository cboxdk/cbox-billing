<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Seams\DatabaseAccountStanding;
use App\Billing\Seams\EloquentInvoicePaymentApplier;
use App\Billing\Seams\PlanExpectedEntitlements;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\DatabaseBillingCurrencyLock;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\Journal\DatabaseRolloutJournal;
use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the billing engine to this app's DURABLE foundation. Two responsibilities,
 * both thin container bindings — no logic lives here:
 *
 *  1. Rebind the engine's swappable stores to their database-backed implementations
 *     (ledger, event log, reconciliation checkpoints, currency lock, rollout journal),
 *     all on the app's default connection, so nothing lives only in process memory.
 *  2. Bind the HOST seams the engine leaves open to app-model-backed implementations:
 *     the invoice payment applier, the meter-policy resolver, the expected-entitlement
 *     oracle, and the durable account-standing store.
 *
 * The stores are also selectable by config (`billing.*`), but binding them explicitly
 * here makes the durable foundation deterministic rather than env-toggled.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerDurableStores();
        $this->registerHostSeams();
    }

    /** Rebind the engine's memory-default stores to their database-backed impls. */
    private function registerDurableStores(): void
    {
        $this->app->singleton(Ledger::class, static fn (Application $app): DatabaseLedger => new DatabaseLedger(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(EventLog::class, static fn (Application $app): DatabaseEventLog => new DatabaseEventLog(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(CheckpointStore::class, static fn (Application $app): DatabaseCheckpointStore => new DatabaseCheckpointStore(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(BillingCurrencyLock::class, static fn (Application $app): DatabaseBillingCurrencyLock => new DatabaseBillingCurrencyLock(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(RolloutJournal::class, static fn (Application $app): DatabaseRolloutJournal => new DatabaseRolloutJournal(
            $app->make('db')->connection(),
            $app->make(EntitlementMeterPolicyResolver::class),
        ));
    }

    /** Bind the host-owned seams to app-model-backed implementations. */
    private function registerHostSeams(): void
    {
        $this->app->singleton(AccountStanding::class, static fn (Application $app): DatabaseAccountStanding => new DatabaseAccountStanding(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(MeterPolicyResolver::class, SubscriptionMeterPolicyResolver::class);

        $this->app->singleton(ExpectedEntitlements::class, PlanExpectedEntitlements::class);

        $this->app->singleton(InvoicePaymentApplier::class, EloquentInvoicePaymentApplier::class);
    }
}
