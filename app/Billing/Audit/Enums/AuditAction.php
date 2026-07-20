<?php

declare(strict_types=1);

namespace App\Billing\Audit\Enums;

use App\Http\Middleware\RecordsOperatorAudit;

/**
 * The typed catalog of operator actions the audit trail records. Every console mutation maps
 * to exactly one case; the value is a stable dotted slug (`<resource>.<verb-past>`) that never
 * changes once shipped, so downstream consumers (SIEM, exports) can pin on it.
 *
 * {@see forRoute()} is the single source of the route-name → action mapping the central
 * recording seam ({@see RecordsOperatorAudit}) uses, so a new mutation
 * route is covered by adding one entry here rather than by touching the middleware. A route
 * with no explicit mapping still records — as {@see ConsoleMutation} — so coverage never
 * silently regresses.
 */
enum AuditAction: string
{
    // Invoices
    case InvoiceCreated = 'invoice.created';
    case InvoiceVoided = 'invoice.voided';
    case InvoiceRefunded = 'invoice.refunded';
    case InvoiceMarkedPaid = 'invoice.marked_paid';
    case InvoiceResent = 'invoice.resent';

    // Wallet
    case WalletAdjusted = 'wallet.adjusted';

    // Customers / organizations
    case CustomerSuspended = 'customer.suspended';
    case CustomerReactivated = 'customer.reactivated';
    case CustomerUpdated = 'customer.updated';
    case CustomerPaymentMethodDefaulted = 'customer.payment_method_defaulted';
    case CustomerPaymentMethodRemoved = 'customer.payment_method_removed';
    case OrganizationFeatureOverridden = 'customer.feature_overridden';

    // Tax exemption certificates
    case ExemptionSubmitted = 'exemption.submitted';
    case ExemptionVerified = 'exemption.verified';
    case ExemptionRejected = 'exemption.rejected';

    // Subscriptions
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionPlanChanged = 'subscription.plan_changed';
    case SubscriptionQuantityChanged = 'subscription.quantity_changed';
    case SubscriptionAddOnAdded = 'subscription.addon_added';
    case SubscriptionAddOnRemoved = 'subscription.addon_removed';
    case SubscriptionCanceled = 'subscription.canceled';
    case SubscriptionReactivated = 'subscription.reactivated';
    case SubscriptionScheduledChangeCanceled = 'subscription.scheduled_change_canceled';
    case SubscriptionSeatAssigned = 'subscription.seat_assigned';
    case SubscriptionSeatUnassigned = 'subscription.seat_unassigned';
    case SubscriptionSeatsSet = 'subscription.seats_set';

    // Dunning
    case DunningRetried = 'dunning.retried';
    case DunningStopped = 'dunning.stopped';

    // CPQ — sales quotes
    case QuoteCreated = 'quote.created';
    case QuoteUpdated = 'quote.updated';
    case QuoteSubmitted = 'quote.submitted';
    case QuoteApproved = 'quote.approved';
    case QuoteRejected = 'quote.rejected';
    case QuoteSent = 'quote.sent';
    case QuoteResent = 'quote.resent';
    case QuoteExpired = 'quote.expired';
    case QuoteCloned = 'quote.cloned';
    case QuoteAccepted = 'quote.accepted';
    case QuoteDeclined = 'quote.declined';
    case QuoteDeleted = 'quote.deleted';

    // A/B pricing experiments
    case ExperimentCreated = 'experiment.created';
    case ExperimentUpdated = 'experiment.updated';
    case ExperimentStarted = 'experiment.started';
    case ExperimentConcluded = 'experiment.concluded';
    case ExperimentDeleted = 'experiment.deleted';

    // Licenses
    case LicenseIssued = 'license.issued';
    case LicenseRenewed = 'license.renewed';
    case LicenseRevoked = 'license.revoked';

    // Catalog: plans
    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanArchived = 'plan.archived';
    case PlanUnarchived = 'plan.unarchived';
    case PlanDeleted = 'plan.deleted';
    case PlanRetired = 'plan.retired';
    case PlanUnretired = 'plan.unretired';
    case PlanEntitlementChanged = 'plan.entitlement_changed';
    case PlanCreditGrantChanged = 'plan.credit_grant_changed';
    case PlanFeatureChanged = 'plan.feature_changed';

    // Catalog: prices / products / meters / coupons
    case PriceCreated = 'price.created';
    case PriceUpdated = 'price.updated';
    case PriceDeleted = 'price.deleted';
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductArchived = 'product.archived';
    case ProductUnarchived = 'product.unarchived';
    case ProductDeleted = 'product.deleted';
    case MeterCreated = 'meter.created';
    case MeterUpdated = 'meter.updated';
    case MeterArchived = 'meter.archived';
    case MeterUnarchived = 'meter.unarchived';
    case MeterDeleted = 'meter.deleted';
    case FeatureCreated = 'feature.created';
    case FeatureUpdated = 'feature.updated';
    case FeatureArchived = 'feature.archived';
    case FeatureUnarchived = 'feature.unarchived';
    case FeatureDeleted = 'feature.deleted';
    case CouponCreated = 'coupon.created';
    case CouponUpdated = 'coupon.updated';
    case CouponArchived = 'coupon.archived';
    case CouponUnarchived = 'coupon.unarchived';
    case CouponDeleted = 'coupon.deleted';

    // Settings: sellers / tokens / webhooks / emails / dunning strategy / fx
    case SellerCreated = 'seller.created';
    case SellerUpdated = 'seller.updated';
    case SellerArchived = 'seller.archived';
    case SellerUnarchived = 'seller.unarchived';
    case SellerDeleted = 'seller.deleted';
    case SellerDefaulted = 'seller.defaulted';
    case TokenMinted = 'token.minted';
    case TokenRevoked = 'token.revoked';
    case WebhookCreated = 'webhook.created';
    case WebhookUpdated = 'webhook.updated';
    case WebhookActivated = 'webhook.activated';
    case WebhookDeactivated = 'webhook.deactivated';
    case WebhookDeleted = 'webhook.deleted';
    case WebhookRedelivered = 'webhook.redelivered';
    case WebhookRotated = 'webhook.rotated';
    case WebhookTested = 'webhook.tested';
    case EmailTemplateUpdated = 'email_template.updated';
    case EmailTemplateReset = 'email_template.reset';
    case EmailTemplateTested = 'email_template.tested';
    case DunningStrategyUpdated = 'dunning_strategy.updated';
    case DunningStrategyReset = 'dunning_strategy.reset';
    case FxOverridesUpdated = 'fx.overrides_updated';
    case FxRefreshed = 'fx.refreshed';

    // Test mode
    case TestClockCreated = 'test_clock.created';
    case TestClockAdvanced = 'test_clock.advanced';
    case TestClockBound = 'test_clock.bound';
    case TestClockUnbound = 'test_clock.unbound';
    case TestClockOutcomeSet = 'test_clock.outcome_set';

    // Data / warehouse
    case WarehouseSinkCreated = 'warehouse_sink.created';
    case WarehouseSinkUpdated = 'warehouse_sink.updated';
    case WarehouseSinkToggled = 'warehouse_sink.toggled';
    case WarehouseSinkDeleted = 'warehouse_sink.deleted';
    case WarehouseSinkRun = 'warehouse_sink.run';
    case DataExported = 'data.exported';
    case DataImported = 'data.imported';

    // GDPR / DSAR / compliance
    case DsarExported = 'dsar.exported';
    case DataErased = 'data.erased';

    // The generic fallback for a mutation route with no explicit mapping — coverage never
    // silently regresses, but a mapped action is always preferable.
    case ConsoleMutation = 'console.mutation';

    /**
     * The action a console mutation route records, or null when the route is not an
     * audit-worthy mutation (a read-only preview, the session-only test-mode toggle, logout).
     * A route that IS a mutation but is not listed maps to {@see ConsoleMutation}.
     */
    public static function forRoute(string $routeName): ?self
    {
        return self::routeMap()[$routeName] ?? null;
    }

    /**
     * Whether a route is an audit-worthy mutation at all. Preview endpoints (which only
     * compute a quote), the persistent test-mode toggle, and auth routes are not.
     */
    public static function isAuditable(string $routeName): bool
    {
        if (str_ends_with($routeName, '.preview')) {
            return false;
        }

        return ! in_array($routeName, self::nonAuditableRoutes(), true);
    }

    /**
     * The route-name → action catalog. Every mutating console route resolves through here.
     *
     * @return array<string, self>
     */
    private static function routeMap(): array
    {
        return [
            'billing.invoices.store' => self::InvoiceCreated,
            'billing.invoices.void' => self::InvoiceVoided,
            'billing.invoices.refund' => self::InvoiceRefunded,
            'billing.invoices.mark-paid' => self::InvoiceMarkedPaid,
            'billing.invoices.resend' => self::InvoiceResent,

            'billing.customers.wallet.adjust' => self::WalletAdjusted,
            'billing.customers.suspend' => self::CustomerSuspended,
            'billing.customers.reactivate' => self::CustomerReactivated,
            'billing.customers.update' => self::CustomerUpdated,
            'billing.customers.payment-methods.default' => self::CustomerPaymentMethodDefaulted,
            'billing.customers.payment-methods.remove' => self::CustomerPaymentMethodRemoved,

            'billing.customers.exemptions.store' => self::ExemptionSubmitted,
            'billing.customers.exemptions.verify' => self::ExemptionVerified,
            'billing.customers.exemptions.reject' => self::ExemptionRejected,

            'billing.subscriptions.store' => self::SubscriptionCreated,
            'billing.subscriptions.plan-change' => self::SubscriptionPlanChanged,
            'billing.subscriptions.quantity' => self::SubscriptionQuantityChanged,
            'billing.subscriptions.addons.add' => self::SubscriptionAddOnAdded,
            'billing.subscriptions.addons.remove' => self::SubscriptionAddOnRemoved,
            'billing.subscriptions.cancel' => self::SubscriptionCanceled,
            'billing.subscriptions.reactivate' => self::SubscriptionReactivated,
            'billing.subscriptions.scheduled-change.cancel' => self::SubscriptionScheduledChangeCanceled,
            'billing.subscriptions.seats.assign' => self::SubscriptionSeatAssigned,
            'billing.subscriptions.seats.unassign' => self::SubscriptionSeatUnassigned,
            'billing.subscriptions.seats.set' => self::SubscriptionSeatsSet,

            'billing.subscriptions.dunning.retry' => self::DunningRetried,
            'billing.subscriptions.dunning.stop' => self::DunningStopped,

            'billing.quotes.store' => self::QuoteCreated,
            'billing.quotes.update' => self::QuoteUpdated,
            'billing.quotes.submit' => self::QuoteSubmitted,
            'billing.quotes.approve' => self::QuoteApproved,
            'billing.quotes.reject' => self::QuoteRejected,
            'billing.quotes.send' => self::QuoteSent,
            'billing.quotes.resend' => self::QuoteResent,
            'billing.quotes.expire' => self::QuoteExpired,
            'billing.quotes.clone' => self::QuoteCloned,
            'billing.quotes.destroy' => self::QuoteDeleted,

            'billing.experiments.store' => self::ExperimentCreated,
            'billing.experiments.update' => self::ExperimentUpdated,
            'billing.experiments.start' => self::ExperimentStarted,
            'billing.experiments.conclude' => self::ExperimentConcluded,
            'billing.experiments.destroy' => self::ExperimentDeleted,

            'billing.licenses.issue' => self::LicenseIssued,
            'billing.licenses.renew' => self::LicenseRenewed,
            'billing.licenses.revoke' => self::LicenseRevoked,

            'billing.plans.store' => self::PlanCreated,
            'billing.plans.update' => self::PlanUpdated,
            'billing.plans.archive' => self::PlanArchived,
            'billing.plans.unarchive' => self::PlanUnarchived,
            'billing.plans.destroy' => self::PlanDeleted,
            'billing.catalog.plans.retire' => self::PlanRetired,
            'billing.catalog.plans.unretire' => self::PlanUnretired,
            'billing.plans.entitlements.store' => self::PlanEntitlementChanged,
            'billing.plans.entitlements.update' => self::PlanEntitlementChanged,
            'billing.plans.entitlements.destroy' => self::PlanEntitlementChanged,
            'billing.plans.credit-grants.store' => self::PlanCreditGrantChanged,
            'billing.plans.credit-grants.update' => self::PlanCreditGrantChanged,
            'billing.plans.credit-grants.destroy' => self::PlanCreditGrantChanged,
            'billing.plans.features.store' => self::PlanFeatureChanged,
            'billing.plans.features.update' => self::PlanFeatureChanged,
            'billing.plans.features.destroy' => self::PlanFeatureChanged,

            'billing.catalog.prices.store' => self::PriceCreated,
            'billing.catalog.prices.update' => self::PriceUpdated,
            'billing.catalog.prices.destroy' => self::PriceDeleted,

            'billing.products.store' => self::ProductCreated,
            'billing.products.update' => self::ProductUpdated,
            'billing.products.archive' => self::ProductArchived,
            'billing.products.unarchive' => self::ProductUnarchived,
            'billing.products.destroy' => self::ProductDeleted,

            'billing.meters.store' => self::MeterCreated,
            'billing.meters.update' => self::MeterUpdated,
            'billing.meters.archive' => self::MeterArchived,
            'billing.meters.unarchive' => self::MeterUnarchived,
            'billing.meters.destroy' => self::MeterDeleted,

            'billing.features.store' => self::FeatureCreated,
            'billing.features.update' => self::FeatureUpdated,
            'billing.features.archive' => self::FeatureArchived,
            'billing.features.unarchive' => self::FeatureUnarchived,
            'billing.features.destroy' => self::FeatureDeleted,

            'billing.customers.features.override' => self::OrganizationFeatureOverridden,
            'billing.customers.features.clear' => self::OrganizationFeatureOverridden,

            'billing.coupons.store' => self::CouponCreated,
            'billing.coupons.update' => self::CouponUpdated,
            'billing.coupons.archive' => self::CouponArchived,
            'billing.coupons.unarchive' => self::CouponUnarchived,
            'billing.coupons.destroy' => self::CouponDeleted,

            'billing.settings.sellers.store' => self::SellerCreated,
            'billing.settings.sellers.update' => self::SellerUpdated,
            'billing.settings.sellers.archive' => self::SellerArchived,
            'billing.settings.sellers.unarchive' => self::SellerUnarchived,
            'billing.settings.sellers.destroy' => self::SellerDeleted,
            'billing.settings.sellers.default' => self::SellerDefaulted,

            'billing.settings.tokens.store' => self::TokenMinted,
            'billing.settings.tokens.revoke' => self::TokenRevoked,

            'billing.settings.webhooks.store' => self::WebhookCreated,
            'billing.settings.webhooks.update' => self::WebhookUpdated,
            'billing.settings.webhooks.activate' => self::WebhookActivated,
            'billing.settings.webhooks.deactivate' => self::WebhookDeactivated,
            'billing.settings.webhooks.destroy' => self::WebhookDeleted,
            'billing.settings.webhooks.redeliver' => self::WebhookRedelivered,
            'billing.settings.webhooks.rotate' => self::WebhookRotated,
            'billing.settings.webhooks.test' => self::WebhookTested,

            'billing.settings.emails.update' => self::EmailTemplateUpdated,
            'billing.settings.emails.reset' => self::EmailTemplateReset,
            'billing.settings.emails.test' => self::EmailTemplateTested,

            'billing.settings.dunning.update' => self::DunningStrategyUpdated,
            'billing.settings.dunning.reset' => self::DunningStrategyReset,

            'billing.settings.fx.overrides' => self::FxOverridesUpdated,
            'billing.settings.fx.refresh' => self::FxRefreshed,

            'billing.test-mode.clocks.store' => self::TestClockCreated,
            'billing.test-mode.clocks.advance' => self::TestClockAdvanced,
            'billing.test-mode.clocks.bind' => self::TestClockBound,
            'billing.test-mode.clocks.unbind' => self::TestClockUnbound,
            'billing.test-mode.clocks.outcome' => self::TestClockOutcomeSet,

            'billing.exports.warehouse.store' => self::WarehouseSinkCreated,
            'billing.exports.warehouse.update' => self::WarehouseSinkUpdated,
            'billing.exports.warehouse.toggle' => self::WarehouseSinkToggled,
            'billing.exports.warehouse.destroy' => self::WarehouseSinkDeleted,
            'billing.exports.warehouse.run' => self::WarehouseSinkRun,

            'billing.import.commit' => self::DataImported,
        ];
    }

    /**
     * Console mutation routes that are deliberately NOT audit-worthy: the persistent
     * test-mode toggle is a per-session UI preference, and auth routes are session lifecycle.
     *
     * @return list<string>
     */
    private static function nonAuditableRoutes(): array
    {
        return ['billing.test-mode.toggle', 'logout', 'auth.demo'];
    }

    /** A short human label for the console filter and the event row. */
    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], [' · ', ' '], $this->value));
    }

    /** The coarse resource group, for the console's action filter and colour coding. */
    public function category(): string
    {
        return explode('.', $this->value)[0];
    }
}
