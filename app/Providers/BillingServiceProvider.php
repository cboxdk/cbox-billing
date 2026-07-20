<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Account\AccountCurrencyResolver;
use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Api\Contracts\ApiTokenAuthenticator;
use App\Billing\Api\DatabaseApiTokenAuthenticator;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Catalog\Contracts\AuthorsPlanPrices;
use App\Billing\Catalog\PlanPriceAuthoring;
use App\Billing\Coupons\Contracts\DiscountsAmounts;
use App\Billing\Coupons\Contracts\RedeemsCoupons;
use App\Billing\Coupons\CouponDiscounter;
use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Enforcement\CacheReservationStore;
use App\Billing\Enforcement\CentralAllowanceLeaseSource;
use App\Billing\Enforcement\Contracts\ReservationStore;
use App\Billing\Enforcement\EventLogUsageBuffer;
use App\Billing\Enforcement\Upgrade\ResolvesRequiredFeaturePlan;
use App\Billing\Enforcement\Upgrade\ResolvesRequiredPlan;
use App\Billing\Enforcement\Upgrade\UpgradeGate;
use App\Billing\Experiments\Contracts\AttributesConversions;
use App\Billing\Experiments\ConversionAttribution;
use App\Billing\Features\Contracts\ResolvesFeatureEntitlements;
use App\Billing\Features\FeatureEntitlements;
use App\Billing\Fx\EcbFxRateSource;
use App\Billing\Fx\EcbRatesParser;
use App\Billing\Fx\FxRateRefresher;
use App\Billing\Fx\StaticFxRateSource;
use App\Billing\Hosted\BillingSessionService;
use App\Billing\Hosted\CheckoutActivation;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Billing\Invoicing\DatabaseCreditNoteNumberSequence;
use App\Billing\Invoicing\DatabaseInvoiceNumberSequence;
use App\Billing\Invoicing\InvoiceOperations;
use App\Billing\Invoicing\InvoiceService;
use App\Billing\Invoicing\PersistIssuedCreditNote;
use App\Billing\Metering\EntitlementsView;
use App\Billing\Metering\UsageSummaryView;
use App\Billing\Mode\BillingContext;
use App\Billing\Notifications\BillingNotifier;
use App\Billing\Notifications\Contracts\ComposesTransactionalMail;
use App\Billing\Notifications\Contracts\ManagesNotificationPreferences;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Notifications\Contracts\RendersTemplates;
use App\Billing\Notifications\Contracts\ResolvesMailTemplates;
use App\Billing\Notifications\NotificationPreferenceService;
use App\Billing\Notifications\Rendering\DefaultMailTemplates;
use App\Billing\Notifications\Rendering\MailTemplateResolver;
use App\Billing\Notifications\Rendering\SafeTemplateRenderer;
use App\Billing\Notifications\Rendering\TransactionalMailComposer;
use App\Billing\Payments\AdaptiveRetryStrategy;
use App\Billing\Payments\Contracts\ClassifiesDeclines;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Payments\Contracts\SchedulesRetries;
use App\Billing\Payments\Contracts\UpdatesCards;
use App\Billing\Payments\Contracts\VerifiesCardUpdates;
use App\Billing\Payments\DatabaseDunningStateStore;
use App\Billing\Payments\DatabaseGatewayCustomerResolver;
use App\Billing\Payments\DatabaseProcessedEventStore;
use App\Billing\Payments\DatabaseSettledPaymentStore;
use App\Billing\Payments\DeclineClassifier;
use App\Billing\Payments\DunningCardUpdater;
use App\Billing\Payments\ManualCardUpdateVerifier;
use App\Billing\Payments\ManualWebhookVerifier;
use App\Billing\Payments\NullCardUpdateVerifier;
use App\Billing\Payments\PaymentRetryService;
use App\Billing\Payments\PaymentService;
use App\Billing\Payments\PlaneAwareWebhookIngest;
use App\Billing\Payments\StripeCardUpdateVerifier;
use App\Billing\Refunds\DatabaseRefundRepository;
use App\Billing\Reporting\Consolidated\ConsolidatedRevenueReport;
use App\Billing\Retention\BasicCancellationSurvey;
use App\Billing\Retention\BasicRetentionOffers;
use App\Billing\Retention\Contracts\ManagesRetention;
use App\Billing\Retention\RetentionService;
use App\Billing\Seams\DatabaseAccountStanding;
use App\Billing\Seams\EloquentInvoicePaymentApplier;
use App\Billing\Seams\PlanExpectedEntitlements;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\SeatManager;
use App\Billing\Seller\ConfiguredEntityRouter;
use App\Billing\Seller\SellerCatalog;
use App\Billing\Storefront\CheckoutLinkBuilder;
use App\Billing\Storefront\Contracts\BuildsCheckoutLinks;
use App\Billing\Subscriptions\Contracts\CollectsProration;
use App\Billing\Subscriptions\Contracts\ConvertsTrials;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\ProrationCharger;
use App\Billing\Subscriptions\SubscriptionDepthService;
use App\Billing\Subscriptions\SubscriptionService;
use App\Billing\Subscriptions\TrialService;
use App\Billing\Support\SubscriptionStanding;
use App\Billing\Wallet\Contracts\AdjustsWallet;
use App\Billing\Wallet\WalletAdjustmentService;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\OrganizationFeatureOverride;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\CurrencyLock\DatabaseBillingCurrencyLock;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\Journal\DatabaseRolloutJournal;
use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Invoice\Contracts\CreditNoteNumberSequence;
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
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Gateways\ManualPaymentGateway;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Cbox\Billing\Refund\Contracts\RefundRepository;
use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Seller\Contracts\EntityRouter;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\FamilyTransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\TransitionEdge;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
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
        $this->registerGatewayCustomers();
        $this->registerHostedSessions();
        $this->registerUpgradeGate();
        $this->registerStorefront();
        $this->registerApi();
        $this->registerRetentionSeam();
        $this->registerFx();

        // The per-org opt-out store for the OPTIONAL lifecycle mails; the notifier consults it
        // before an optional send and the portal reads/writes it from the toggle UI.
        $this->app->singleton(ManagesNotificationPreferences::class, NotificationPreferenceService::class);

        // The customer-facing transactional mail surface: one place resolves the billing
        // contact and queues the branded Mailable for each lifecycle event.
        $this->app->singleton(NotifiesCustomers::class, BillingNotifier::class);

        $this->registerTransactionalMail();
    }

    /**
     * The brandable + localized transactional-email system: a sandboxed template renderer
     * (never Blade/PHP over stored templates), the shipped defaults loaded from
     * resources/mail-templates, the layered resolver, and the end-to-end composer. All behind
     * contracts so the console and the notifier render identically and the pieces are fakeable.
     */
    private function registerTransactionalMail(): void
    {
        $this->app->singleton(RendersTemplates::class, SafeTemplateRenderer::class);

        $this->app->singleton(
            DefaultMailTemplates::class,
            static fn (): DefaultMailTemplates => new DefaultMailTemplates(resource_path('mail-templates')),
        );

        $this->app->singleton(ResolvesMailTemplates::class, MailTemplateResolver::class);
        $this->app->singleton(ComposesTransactionalMail::class, TransactionalMailComposer::class);
    }

    public function boot(): void
    {
        // Persist the durable credit note the engine issues on every refund/adjustment:
        // the refund flow fires CreditNoteIssued after drawing the number and posting the
        // reversal, and the listener writes the app's record surface from it.
        Event::listen(CreditNoteIssued::class, [PersistIssuedCreditNote::class, 'handle']);

        // Keep the meter-policy resolver's per-request memoization (PERF-2) correct: any write
        // to a subscription, a plan entitlement or the meter catalog can change what a meter
        // resolves to, so flush the memo on those writes. These fire rarely (lifecycle/catalog
        // changes), never on the enforcement hot path (which writes leases/events, not these),
        // so the memo still survives a whole entitlements read. `app()` targets the current
        // container, so the flush always hits the live singleton.
        $flush = static function (): void {
            app(SubscriptionMeterPolicyResolver::class)->flush();
        };

        foreach ([Subscription::class, PlanEntitlement::class, Meter::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }

        // The same PERF-2 invalidation for the boolean/config feature resolver: a write to a plan
        // grant, an org override, the feature catalog, or the serving subscription can change what
        // a feature resolves to, so flush the feature memo on those writes. These fire only on
        // catalog/lifecycle changes, never on the enforcement hot path, so the memo still survives
        // a whole feature-set read.
        $flushFeatures = static function (): void {
            app(FeatureEntitlements::class)->flush();
        };

        foreach ([Subscription::class, PlanFeature::class, OrganizationFeatureOverride::class, Feature::class] as $model) {
            $model::saved($flushFeatures);
            $model::deleted($flushFeatures);
        }

        // Bust the consolidated-revenue book-wide aggregate (PERF-1) on any write that can move
        // reported MRR: a subscription (status/seats/coupon binding), a plan price/tier, or an
        // invoice (which reassigns a subscription's selling entity of record). The aggregate is
        // otherwise cached across renders, so the multi-entity/currency console stops rehydrating
        // the whole subscriptions + invoices tables on every page load.
        $flushConsolidated = static function (): void {
            app(ConsolidatedRevenueReport::class)->flush();
        };

        foreach ([Subscription::class, SubscriptionCoupon::class, Plan::class, PlanPrice::class, Invoice::class] as $model) {
            $model::saved($flushConsolidated);
            $model::deleted($flushConsolidated);
        }

        // Maintain the materialized console display standing (PERF-3). A subscription write can
        // change its own standing; an invoice write can change the org's standing (the
        // overdue-open-invoice fallback is org-scoped). Both recompute through the same
        // derivation, so the column always equals the live computation. The recompute writes
        // via the base query builder (no model event), so these observers never recurse.
        Subscription::saved(static function (Subscription $subscription): void {
            SubscriptionStanding::refreshFor($subscription);
        });

        $refreshOrg = static function (Invoice $invoice): void {
            SubscriptionStanding::refreshForOrg($invoice->organization_id);
        };
        Invoice::saved($refreshOrg);
        Invoice::deleted($refreshOrg);
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
                $app->make(ResolvesRequiredFeaturePlan::class),
                $app->make(ManagesBillingSessions::class),
                $app->make(EntitlementsView::class),
                $app->make(UsageSummaryView::class),
                is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : $app->make(UrlGenerator::class)->to('/'),
            );
        });
    }

    /**
     * Bind the embeddable storefront (#57). The {@see CheckoutLinkBuilder} needs the default CTA
     * hand-off target — the operator's checkout entry a pricing table's CTA deep-links into when
     * it sets no `cta_url_template` of its own — which falls back to the app root so a CTA is
     * always a valid link. The presenter/report/authoring resolve straight from the container.
     */
    private function registerStorefront(): void
    {
        $this->app->singleton(CheckoutLinkBuilder::class, static function (Application $app): CheckoutLinkBuilder {
            $checkoutUrl = $app->make(Config::class)->get('billing.storefront.checkout_url');

            // Falls back to the app-root PATH (relative) rather than an absolute URL so a table
            // with no configured checkout target stays fully self-contained (no external host in
            // its CTA hrefs); an operator points it at their real checkout via the config or the
            // table's own cta_url_template.
            return new CheckoutLinkBuilder(
                is_string($checkoutUrl) && $checkoutUrl !== '' ? $checkoutUrl : '/',
            );
        });
    }

    /**
     * Wire the gateway customer mapping (ADR-0009 Path B). Intents are created against the
     * gateway's own customer handle (`cus_…`), never the raw org id, so the resolver mints
     * that handle once per `(org, gateway)` and reuses it. Both the minting and the detach
     * are the engine gateway's own operations (`createCustomer` / `detachPaymentMethod` on
     * the bound {@see PaymentGateway}), so the app reaches into no gateway SDK directly.
     */
    private function registerGatewayCustomers(): void
    {
        $this->app->singleton(ResolvesGatewayCustomer::class, static fn (Application $app): DatabaseGatewayCustomerResolver => new DatabaseGatewayCustomerResolver(
            $app->make(PaymentGateway::class),
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

        // The credit note's own gapless legal sequence (Wave 3) and the durable refund
        // record — over the engine's in-memory defaults — so credit-note numbering
        // persists consistently with invoice numbering and the over-refund cap +
        // idempotency hold across requests.
        $this->app->singleton(CreditNoteNumberSequence::class, static fn (Application $app): DatabaseCreditNoteNumberSequence => new DatabaseCreditNoteNumberSequence(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(RefundRepository::class, static fn (Application $app): DatabaseRefundRepository => new DatabaseRefundRepository(
            $app->make('db')->connection(),
            $app->make(SellerCatalog::class),
            $app->make(BillingContext::class),
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
            $app->make(BillingContext::class),
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

        // The boolean/config feature-entitlements resolver — the gating sibling of the metered
        // resolver above. A per-request container singleton so its per-org memoization (reusing
        // PERF-2) lives exactly one request; the boot() hook flushes it on a grant/override/
        // subscription write.
        $this->app->singleton(FeatureEntitlements::class);

        // Contracts-first DI: bind each module's interface to its concrete so callers depend on
        // the contract (money paths especially). The feature resolver's interface must resolve the
        // SAME singleton the flush hook targets, so it is aliased rather than newly constructed.
        $this->app->singleton(RedeemsCoupons::class, CouponRedeemer::class);
        $this->app->singleton(DiscountsAmounts::class, CouponDiscounter::class);
        $this->app->singleton(AttributesConversions::class, ConversionAttribution::class);
        $this->app->alias(FeatureEntitlements::class, ResolvesFeatureEntitlements::class);
        $this->app->singleton(BuildsCheckoutLinks::class, static fn (Application $app): CheckoutLinkBuilder => $app->make(CheckoutLinkBuilder::class));

        // The settled-webhook effect, decorated to ALSO activate a hosted checkout
        // (ADR-0009): a checkout's subscription is created strictly on the gateway's
        // settled webhook — the decorator wraps the plain invoice applier, so an ordinary
        // invoice/renewal reference still marks its invoice paid.
        $this->app->singleton(InvoicePaymentApplier::class, static fn (Application $app): CheckoutActivation => new CheckoutActivation(
            $app->make(EloquentInvoicePaymentApplier::class),
            $app->make(SubscribesOrganizations::class),
            $app->make(ManagesBillingSessions::class),
            $app->make(CouponRedeemer::class),
            $app->make(ConversionAttribution::class),
            $app->make(BillingContext::class),
            $app->make(RecordsAudit::class),
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
            $app->make(BillingContext::class),
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

        $this->app->singleton(CollectsProration::class, ProrationCharger::class);

        $this->app->singleton(SubscribesOrganizations::class, SubscriptionService::class);

        $this->app->singleton(AuthorsPlanPrices::class, PlanPriceAuthoring::class);

        $this->app->singleton(ManagesSubscriptionDepth::class, SubscriptionDepthService::class);

        // The purchased + explicitly-assigned seat model: purchased Full seats drive billing
        // through the depth service's changeQuantity; assignment is app-side over the mirror.
        $this->app->singleton(ManagesSeats::class, SeatManager::class);

        $this->app->singleton(ManagesRetention::class, RetentionService::class);

        $this->app->singleton(GeneratesInvoices::class, InvoiceService::class);

        // The invoice lifecycle-operations surface (void / refund → credit note /
        // mark-paid / resend / ad-hoc create) — money through the engine refunder +
        // invoicer, guards enforced server-side.
        $this->app->singleton(RunsInvoiceOperations::class, InvoiceOperations::class);

        $this->app->singleton(PaysInvoices::class, PaymentService::class);

        // Adaptive dunning: the decline taxonomy (classifies a gateway failure into a recovery
        // category) and the per-category adaptive retry schedule the collector drives off it.
        $this->app->singleton(ClassifiesDeclines::class, DeclineClassifier::class);
        $this->app->singleton(SchedulesRetries::class, AdaptiveRetryStrategy::class);

        // The smart-retry dunning collector (failed renewal → classify → PastDue → adaptive
        // backoff retries → recover or terminal action) and the trial-conversion service.
        $this->app->singleton(RetriesPayments::class, PaymentRetryService::class);

        // The card / account-updater seam: a gateway card-update points the vaulted default at
        // the fresh card and re-attempts the account's in-dunning charges. NullCardUpdater is
        // the inert default; the deployable app binds the working DunningCardUpdater.
        $this->app->singleton(UpdatesCards::class, DunningCardUpdater::class);

        $this->app->singleton(ConvertsTrials::class, TrialService::class);

        // Operator wallet adjustments (Wave 3): grant/debit through the engine wallet with
        // an audit row and the no-debt-beyond-policy guardrail.
        $this->app->singleton(AdjustsWallet::class, WalletAdjustmentService::class);
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
            $app->make(BillingContext::class),
        ));

        $this->app->singleton(SettledPaymentStore::class, static fn (Application $app): DatabaseSettledPaymentStore => new DatabaseSettledPaymentStore(
            $app->make('db')->connection(),
            $app->make(BillingContext::class),
        ));

        // Override the engine's DefaultWebhookIngest with the app's plane-aware, rejection-aware
        // ingest: it bootstraps the request plane from the reference's owning plane before any
        // mode-scoped read (HP1), and aborts before the dedup guards when the applier refuses a
        // settlement, so a corrected retry still applies.
        $this->app->singleton(WebhookIngest::class, static fn (Application $app): PlaneAwareWebhookIngest => new PlaneAwareWebhookIngest(
            $app->make(ProcessedEventStore::class),
            $app->make(SettledPaymentStore::class),
            $app->make(InvoicePaymentApplier::class),
            $app->make(BillingContext::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(DunningStateStore::class, static fn (Application $app): DatabaseDunningStateStore => new DatabaseDunningStateStore(
            $app->make('db')->connection(),
            $app->make(BillingContext::class),
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

        // The card / account-updater verifier — the seam the engine's settlement webhook does
        // not model. Prefer the REAL Stripe verifier when its signing secret is set (it consumes
        // `payment_method.automatically_updated` & friends the settlement adapter ignores);
        // otherwise the manual HMAC verifier when a webhook secret is set; else deny-by-default.
        $this->app->singleton(VerifiesCardUpdates::class, static function (Application $app): VerifiesCardUpdates {
            $config = $app->make(Config::class);
            $stripeSecret = $config->get('billing-stripe.webhook_secret');

            if (is_string($stripeSecret) && $stripeSecret !== '') {
                return new StripeCardUpdateVerifier($stripeSecret);
            }

            $secret = $config->get('billing.webhook.secret');
            $header = $config->get('billing.webhook.signature_header', 'X-Cbox-Signature');

            if (is_string($secret) && $secret !== '') {
                return new ManualCardUpdateVerifier(
                    secret: $secret,
                    signatureHeader: is_string($header) ? $header : 'X-Cbox-Signature',
                );
            }

            return new NullCardUpdateVerifier;
        });
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

    /**
     * Bind the engine's retention seam to the app's own basic defaults (a built-in reason
     * survey + a pause save-offer), so the cancel flow consults a real survey/offers rather
     * than the engine's inert Null defaults. These are plain (unconditional) bindings — the
     * same override pattern every host seam here uses — over the engine's `bindIf` Nulls; a
     * composed `cbox-billing-retention` plugin rebinds both contracts to its rich flow (its
     * provider boots after this one), enriching the flow with zero app edits.
     *
     * The {@see RetentionRecorder} the cancel path emits through stays the engine's — it is
     * bound with the real dispatcher by the engine's RetentionServiceProvider — so the app
     * emits the retention domain events a plugin listens for.
     */
    private function registerRetentionSeam(): void
    {
        $this->app->singleton(CancellationSurvey::class, BasicCancellationSurvey::class);
        $this->app->singleton(RetentionOffers::class, BasicRetentionOffers::class);
    }

    /**
     * The FX-rate ingestion for consolidated reporting: the ECB feed adapter (real, cited) and
     * the operator-override source, assembled into the refresher in the config-declared order.
     * The converter and repository auto-wire from their constructors, so only the sources and the
     * refresher's source list need binding. Rates are only ever read from here — never fabricated.
     */
    private function registerFx(): void
    {
        $this->app->singleton(EcbFxRateSource::class, static function (Application $app): EcbFxRateSource {
            $url = $app->make(Config::class)->get('billing.fx.ecb.url');

            return new EcbFxRateSource(
                $app->make(HttpFactory::class),
                $app->make(EcbRatesParser::class),
                is_string($url) ? $url : 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
            );
        });

        $this->app->singleton(FxRateRefresher::class, static function (Application $app): FxRateRefresher {
            $configured = $app->make(Config::class)->get('billing.fx.sources', ['ecb', 'override']);
            $names = is_array($configured) ? $configured : ['ecb', 'override'];

            $sources = [];
            foreach ($names as $name) {
                $source = match ($name) {
                    'ecb' => $app->make(EcbFxRateSource::class),
                    'override' => $app->make(StaticFxRateSource::class),
                    default => null,
                };

                if ($source !== null) {
                    $sources[] = $source;
                }
            }

            return new FxRateRefresher($sources);
        });
    }

    /** Bind the pluggable API token authenticator (operator static token + per-org rows). */
    private function registerApi(): void
    {
        $this->app->singleton(ApiTokenAuthenticator::class, static function (Application $app): DatabaseApiTokenAuthenticator {
            $config = $app->make(Config::class);
            $token = $config->get('billing.api.static_token');
            $throttle = $config->get('billing.api.last_used_throttle_seconds', 300);

            return new DatabaseApiTokenAuthenticator(
                is_string($token) ? $token : null,
                is_numeric($throttle) ? (int) $throttle : 300,
            );
        });
    }
}
