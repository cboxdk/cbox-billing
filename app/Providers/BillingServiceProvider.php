<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Account\AccountCurrencyResolver;
use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Api\Contracts\ApiTokenAuthenticator;
use App\Billing\Api\DatabaseApiTokenAuthenticator;
use App\Billing\Enforcement\CacheReservationStore;
use App\Billing\Enforcement\CentralAllowanceLeaseSource;
use App\Billing\Enforcement\Contracts\ReservationStore;
use App\Billing\Enforcement\EventLogUsageBuffer;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\DatabaseInvoiceNumberSequence;
use App\Billing\Invoicing\InvoiceService;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\DatabaseDunningStateStore;
use App\Billing\Payments\DatabaseProcessedEventStore;
use App\Billing\Payments\DatabaseSettledPaymentStore;
use App\Billing\Payments\ManualWebhookVerifier;
use App\Billing\Payments\PaymentService;
use App\Billing\Seams\DatabaseAccountStanding;
use App\Billing\Seams\EloquentInvoicePaymentApplier;
use App\Billing\Seams\PlanExpectedEntitlements;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use App\Billing\Seller\ConfiguredEntityRouter;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\SubscriptionService;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\DatabaseBillingCurrencyLock;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\Journal\DatabaseRolloutJournal;
use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Contracts\UsageBuffer;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\LeasedEnforcement;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Cbox\Billing\Seller\Contracts\EntityRouter;
use Illuminate\Contracts\Config\Repository as Config;
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
        $this->registerEnforcement();
        $this->registerLifecycleServices();
        $this->registerPaymentSeams();
        $this->registerApi();
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
        $this->app->singleton(ResolvesAccountCurrency::class, static function (Application $app): AccountCurrencyResolver {
            $default = $app->make(Config::class)->get('billing.default_currency', 'DKK');

            return new AccountCurrencyResolver(
                $app->make(BillingCurrencyLock::class),
                is_string($default) ? $default : 'DKK',
            );
        });

        $this->app->singleton(AccountStanding::class, static fn (Application $app): DatabaseAccountStanding => new DatabaseAccountStanding(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(MeterPolicyResolver::class, SubscriptionMeterPolicyResolver::class);

        $this->app->singleton(ExpectedEntitlements::class, PlanExpectedEntitlements::class);

        $this->app->singleton(InvoicePaymentApplier::class, EloquentInvoicePaymentApplier::class);
    }

    /**
     * Wire the server-side enforcement hot path. The engine leaves `Enforcement`,
     * `AllowanceLeaseSource` and `UsageBuffer` unbound (they are the SDK's on an edge
     * node); here billing IS the authority, so we bind them to the central budget, the
     * durable event log, and the lease-backed enforcer that composes them.
     */
    private function registerEnforcement(): void
    {
        $this->app->singleton(AllowanceLeaseSource::class, static fn (Application $app): CentralAllowanceLeaseSource => new CentralAllowanceLeaseSource(
            $app->make('db')->connection(),
            $app->make(MeterPolicyResolver::class),
        ));

        $this->app->singleton(UsageBuffer::class, static fn (Application $app): EventLogUsageBuffer => new EventLogUsageBuffer(
            $app->make(EventLog::class),
        ));

        $this->app->singleton(Enforcement::class, static function (Application $app): LeasedEnforcement {
            $config = $app->make(Config::class);
            $refill = $config->get('billing.metering.lease.default_size', 100);

            return new LeasedEnforcement(
                store: $app->make(LocalStore::class),
                source: $app->make(AllowanceLeaseSource::class),
                buffer: $app->make(UsageBuffer::class),
                service: 'api',
                refillSize: is_numeric($refill) ? (int) $refill : 100,
                policies: $app->make(MeterPolicyResolver::class),
                signals: $app->make(EnforcementSignals::class),
                infraPolicy: $app->make(InfraFailurePolicy::class),
            );
        });

        $this->app->singleton(ReservationStore::class, static fn (Application $app): CacheReservationStore => new CacheReservationStore(
            $app->make('cache')->store(),
        ));
    }

    /** Bind the app's lifecycle services and the durable per-entity invoice numbering. */
    private function registerLifecycleServices(): void
    {
        $this->app->singleton(InvoiceNumberSequence::class, static fn (Application $app): DatabaseInvoiceNumberSequence => new DatabaseInvoiceNumberSequence(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(EntityRouter::class, ConfiguredEntityRouter::class);

        $this->app->singleton(SubscribesOrganizations::class, SubscriptionService::class);

        $this->app->singleton(GeneratesInvoices::class, InvoiceService::class);

        $this->app->singleton(PaysInvoices::class, PaymentService::class);
    }

    /**
     * Rebind the payment seams the engine defaults to in-memory: the exactly-once webhook
     * guards and the dunning-state store go durable, and the webhook verifier becomes the
     * HMAC-signed manual verifier (deny-by-default when no secret is configured).
     */
    private function registerPaymentSeams(): void
    {
        $this->app->singleton(ProcessedEventStore::class, static fn (Application $app): DatabaseProcessedEventStore => new DatabaseProcessedEventStore(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(SettledPaymentStore::class, static fn (Application $app): DatabaseSettledPaymentStore => new DatabaseSettledPaymentStore(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(DunningStateStore::class, static fn (Application $app): DatabaseDunningStateStore => new DatabaseDunningStateStore(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(WebhookVerifier::class, static function (Application $app): ManualWebhookVerifier {
            $config = $app->make(Config::class);
            $secret = $config->get('billing.webhook.secret');
            $header = $config->get('billing.webhook.signature_header', 'X-Cbox-Signature');

            return new ManualWebhookVerifier(
                secret: is_string($secret) ? $secret : null,
                signatureHeader: is_string($header) ? $header : 'X-Cbox-Signature',
            );
        });
    }

    /** Bind the pluggable API token authenticator (operator static token + per-org rows). */
    private function registerApi(): void
    {
        $this->app->singleton(ApiTokenAuthenticator::class, static function (Application $app): DatabaseApiTokenAuthenticator {
            $token = $app->make(Config::class)->get('billing.api.static_token');

            return new DatabaseApiTokenAuthenticator(is_string($token) ? $token : null);
        });
    }
}
