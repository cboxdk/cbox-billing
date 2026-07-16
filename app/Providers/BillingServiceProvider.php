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
use App\Billing\Enforcement\Upgrade\ResolvesRequiredPlan;
use App\Billing\Enforcement\Upgrade\UpgradeGate;
use App\Billing\Hosted\BillingSessionService;
use App\Billing\Hosted\CheckoutActivation;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\DatabaseInvoiceNumberSequence;
use App\Billing\Invoicing\InvoiceService;
use App\Billing\Metering\EntitlementsView;
use App\Billing\Metering\UsageSummaryView;
use App\Billing\Payments\Contracts\CreatesGatewayCustomer;
use App\Billing\Payments\Contracts\DetachesPaymentMethod;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Billing\Payments\DatabaseDunningStateStore;
use App\Billing\Payments\DatabaseGatewayCustomerResolver;
use App\Billing\Payments\DatabaseProcessedEventStore;
use App\Billing\Payments\DatabaseSettledPaymentStore;
use App\Billing\Payments\ManualGatewayCustomerFactory;
use App\Billing\Payments\ManualPaymentMethodDetacher;
use App\Billing\Payments\ManualWebhookVerifier;
use App\Billing\Payments\PaymentService;
use App\Billing\Payments\StripeGatewayCustomerFactory;
use App\Billing\Payments\StripePaymentMethodDetacher;
use App\Billing\Seams\DatabaseAccountStanding;
use App\Billing\Seams\EloquentInvoicePaymentApplier;
use App\Billing\Seams\PlanExpectedEntitlements;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use App\Billing\Seller\ConfiguredEntityRouter;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\SubscriptionDepthService;
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
use Cbox\Billing\Metering\Sources\WalletIncludedAllowanceResolver;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Gateways\ManualPaymentGateway;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Cbox\Billing\Seller\Contracts\EntityRouter;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\FamilyTransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionEdge;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

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
        $this->registerGatewayCustomers();
        $this->registerHostedSessions();
        $this->registerUpgradeGate();
        $this->registerApi();
    }

    /**
     * Bind the enforce→upgrade bridge (#52). The gate resolves the minimum reachable plan
     * that grants a refused meter and mints the pre-built checkout deep-link to buy it; its
     * return URL is where the hosted checkout lands the customer once payment settles.
     */
    private function registerUpgradeGate(): void
    {
        $this->app->singleton(UpgradeGate::class, static function (Application $app): UpgradeGate {
            $returnUrl = $app->make(Config::class)->get('billing.hosted.upgrade_return_url');

            return new UpgradeGate(
                $app->make(ResolvesRequiredPlan::class),
                $app->make(ManagesBillingSessions::class),
                $app->make(EntitlementsView::class),
                $app->make(UsageSummaryView::class),
                is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : $app->make(UrlGenerator::class)->to('/'),
            );
        });
    }

    /**
     * Wire the gateway customer mapping (ADR-0009 Path B). Intents are created against the
     * gateway's own customer handle (`cus_…`), never the raw org id, so the resolver mints
     * that handle once per `(org, gateway)` and reuses it. The creation half and the detach
     * seam are gateway-specific: bound to the Stripe SDK when Stripe is configured, and to
     * the vault-less manual implementations otherwise.
     */
    private function registerGatewayCustomers(): void
    {
        $config = $this->app->make(Config::class);

        if ($this->stripeGatewayConfigured($config)) {
            $secret = $config->get('billing-stripe.secret');
            $client = new StripeClient(is_string($secret) ? $secret : '');

            $this->app->singleton(CreatesGatewayCustomer::class, static fn (): StripeGatewayCustomerFactory => new StripeGatewayCustomerFactory($client));
            $this->app->singleton(DetachesPaymentMethod::class, static fn (): StripePaymentMethodDetacher => new StripePaymentMethodDetacher($client));
        } else {
            $this->app->singleton(CreatesGatewayCustomer::class, ManualGatewayCustomerFactory::class);
            $this->app->singleton(DetachesPaymentMethod::class, ManualPaymentMethodDetacher::class);
        }

        $this->app->singleton(ResolvesGatewayCustomer::class, static fn (Application $app): DatabaseGatewayCustomerResolver => new DatabaseGatewayCustomerResolver(
            $app->make(PaymentGateway::class),
            $app->make(CreatesGatewayCustomer::class),
        ));
    }

    /**
     * Bind the hosted checkout + customer-portal session manager (ADR-0009 Path A). The
     * TTL bounds how long an opaque session token authorizes its page.
     */
    private function registerHostedSessions(): void
    {
        $this->app->singleton(ManagesBillingSessions::class, static function (Application $app): BillingSessionService {
            $ttl = $app->make(Config::class)->get('billing.hosted.session_ttl_minutes', 30);

            return new BillingSessionService(is_numeric($ttl) ? (int) $ttl : 30);
        });
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

        // The meter-policy resolver is the app's subscription→plan→entitlement decision
        // (enabled? · weight · overage) DECORATED so each meter's included allowance is
        // sourced from its `included`-pool wallet balance (ADR-0013) rather than the
        // hand-authored scalar — the wallet is the home of the exempt size.
        $this->app->singleton(SubscriptionMeterPolicyResolver::class);

        $this->app->singleton(MeterPolicyResolver::class, static fn (Application $app): WalletIncludedAllowanceResolver => new WalletIncludedAllowanceResolver(
            $app->make(SubscriptionMeterPolicyResolver::class),
            $app->make(Wallet::class),
        ));

        $this->app->singleton(ExpectedEntitlements::class, PlanExpectedEntitlements::class);

        // The settled-webhook effect, decorated to ALSO activate a hosted checkout
        // (ADR-0009): a checkout's subscription is created strictly on the gateway's
        // settled webhook — the decorator wraps the plain invoice applier, so an ordinary
        // invoice/renewal reference still marks its invoice paid.
        $this->app->singleton(InvoicePaymentApplier::class, static fn (Application $app): CheckoutActivation => new CheckoutActivation(
            $app->make(EloquentInvoicePaymentApplier::class),
            $app->make(SubscribesOrganizations::class),
            $app->make(ManagesBillingSessions::class),
        ));

        $this->registerTransitionPolicy();
    }

    /**
     * Bind the engine's {@see TransitionPolicy} to a {@see FamilyTransitionPolicy} built
     * from the catalog's declared cross-family edges (`billing.transitions`). Plans in one
     * product share a family and move freely; a cross-family move is deny-by-default and
     * only allowed along a declared edge (ADR-0010).
     */
    private function registerTransitionPolicy(): void
    {
        $this->app->singleton(TransitionPolicy::class, static function (Application $app): FamilyTransitionPolicy {
            $declared = $app->make(Config::class)->get('billing.transitions', []);
            $edges = [];

            foreach (is_array($declared) ? $declared : [] as $edge) {
                if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)) {
                    continue;
                }

                $guidance = $edge['guidance'] ?? null;

                $edges[] = new TransitionEdge(
                    fromFamily: $edge['from'],
                    toFamily: $edge['to'],
                    guidance: is_string($guidance) ? $guidance : null,
                    carryOver: (bool) ($edge['carry_over'] ?? false),
                );
            }

            return new FamilyTransitionPolicy(...$edges);
        });
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

        $this->app->singleton(ManagesSubscriptionDepth::class, SubscriptionDepthService::class);

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
        $config = $this->app->make(Config::class);

        // The bound gateway: the Stripe adapter binds itself as the PaymentGateway (and its
        // own verifier) when its keys are configured — we only supply the dependency-free
        // ManualPaymentGateway as the fallback when no gateway keys are set, and leave the
        // Stripe binding untouched when they are.
        if (! $this->stripeGatewayConfigured($config)) {
            $this->app->singleton(PaymentGateway::class, static fn (): ManualPaymentGateway => new ManualPaymentGateway);
        }

        $this->app->singleton(ProcessedEventStore::class, static fn (Application $app): DatabaseProcessedEventStore => new DatabaseProcessedEventStore(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(SettledPaymentStore::class, static fn (Application $app): DatabaseSettledPaymentStore => new DatabaseSettledPaymentStore(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(DunningStateStore::class, static fn (Application $app): DatabaseDunningStateStore => new DatabaseDunningStateStore(
            $app->make('db')->connection(),
        ));

        // The manual HMAC verifier backs the manual gateway's settlement webhook. When the
        // Stripe adapter is configured with a signing secret it binds its OWN verifier, so
        // we only bind the manual one when Stripe's is not in play.
        if (! $this->stripeWebhookConfigured($config)) {
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
    }

    /** Whether a Stripe secret key is configured, so the Stripe gateway is the bound one. */
    private function stripeGatewayConfigured(Config $config): bool
    {
        $secret = $config->get('billing-stripe.secret');

        return is_string($secret) && $secret !== '';
    }

    /** Whether a Stripe webhook signing secret is configured (so Stripe binds its verifier). */
    private function stripeWebhookConfigured(Config $config): bool
    {
        $secret = $config->get('billing-stripe.webhook_secret');

        return is_string($secret) && $secret !== '';
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
