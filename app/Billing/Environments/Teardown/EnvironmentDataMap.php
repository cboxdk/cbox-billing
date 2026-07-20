<?php

declare(strict_types=1);

namespace App\Billing\Environments\Teardown;

use App\Billing\Environments\EnvironmentCloner;

/**
 * The authoritative catalog of the environment-scoped tables, split into the two categories the
 * reset and destroy operations key off. It is the single source of truth for "what belongs to a
 * plane" and "what is config vs. runtime", so the teardown scope is auditable in one place rather
 * than scattered across services.
 *
 * CONFIG ({@see configTables()}) — survives a sandbox RESET, deleted by an environment DESTROY:
 *   - the catalog/branding/templates/storefront/experiments-config/dunning-strategies/coupons —
 *     exactly the surface {@see EnvironmentCloner} deep-copies, so a
 *     reset returns a plane to its freshly-cloned config state;
 *   - the operator-configured INFRASTRUCTURE of the plane — its gateway credentials, outbound
 *     webhook receivers and warehouse sinks. These are operator setup, not tenant/runtime data, so
 *     a reset (which wipes the book) keeps them rather than forcing the operator to reconfigure.
 *
 * TRANSACTIONAL ({@see transactionalTables()}) — wiped by BOTH reset and destroy: the runtime
 * book and tenant state (subscriptions, invoices, customers, ledger/wallet, dunning STATE,
 * redemptions, licenses, webhook DELIVERIES, seats, quotes, import runs, dedup/settlement stores,
 * the sandbox's own test-clock rows, …) — everything that is not config. The global, append-only
 * audit trail (`operator_audit_events`) is NOT in either list — see {@see transactionalTables()}.
 *
 * Every table is listed with its `environment` partition column so a teardown is a plain
 * `DELETE … WHERE environment = ?` (bypassing the model scope); callers guard each with a
 * schema check so a not-yet-migrated or plugin-added table is skipped, never assumed.
 */
readonly class EnvironmentDataMap
{
    /**
     * Config + operator-infra tables (kept on reset, deleted on destroy).
     *
     * @return list<string>
     */
    public function configTables(): array
    {
        return [
            // Catalog roots + children (the cloner's deep-copy surface).
            'meters', 'features', 'products',
            'plans', 'plan_prices', 'plan_price_tiers', 'plan_entitlements', 'plan_credit_grants', 'plan_features',
            // Seller register + branding + tax registrations + mail templates.
            'seller_entities', 'seller_tax_registrations', 'mail_templates',
            // Storefront.
            'pricing_tables', 'pricing_table_plans', 'pricing_table_features',
            // Experiments (config side — impressions/conversions are transactional).
            'experiments', 'experiment_variants',
            // Per-category dunning strategy config + coupons (config, counters reset on clone).
            'dunning_strategies', 'coupons',
            // Operator infrastructure of the plane (kept on reset, not tenant/runtime data).
            'environment_gateways', 'webhook_endpoints', 'warehouse_sinks',
        ];
    }

    /**
     * Transactional / tenant / operational tables (wiped on reset AND destroy).
     *
     * @return list<string>
     */
    public function transactionalTables(): array
    {
        return [
            // Tenant + subscription book.
            'organizations', 'subscriptions', 'subscription_coupons', 'invoices', 'credit_notes', 'refunds',
            'coupon_redemptions', 'seat_assignments', 'wallet_adjustments', 'payment_retries',
            'issued_licenses', 'license_revocations',
            // A/B experiment metrics (transactional counters; the experiments themselves are config).
            'experiment_impressions', 'experiment_conversions',
            // Webhook deliveries + dedup/settlement stores (endpoints themselves are config).
            'webhook_deliveries', 'webhook_processed_events', 'settled_payments',
            // Hosted sessions, quotes, exemptions, per-org overrides + leases.
            'billing_sessions', 'quotes', 'quote_lines', 'quote_acceptances',
            'tax_exemption_certificates', 'organization_feature_overrides', 'allowance_leases',
            'gateway_customers', 'usage_alert_dispatches',
            // Per-org notification opt-out ledger (tenant preference state).
            'notification_preferences',
            // Dunning + standing runtime state.
            'account_standings', 'dunning_states',
            // Approvals, imports, and test clocks.
            'approval_requests', 'import_runs', 'import_run_entries', 'import_source_refs',
            'test_clocks',
            // NOTE: operator_audit_events is deliberately NOT here. It is a GLOBAL, hash-chained,
            // append-only trail (a BEFORE DELETE trigger refuses any delete); bulk-deleting a
            // plane's slice would both raise an uncaught QueryException (→ 500 on reset/destroy) and
            // break the chain's sequence/prev_hash continuity. A sandbox's audit rows are stamped
            // with its environment key and simply RETAINED after the plane is torn down.
        ];
    }

    /**
     * Every environment-scoped table (config first, then transactional) — the full set a destroy
     * removes for a plane.
     *
     * @return list<string>
     */
    public function allTables(): array
    {
        return [...$this->configTables(), ...$this->transactionalTables()];
    }
}
