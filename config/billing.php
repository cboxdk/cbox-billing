<?php

declare(strict_types=1);

return [

    /*
     * Billing-account invariants.
     */
    'account' => [

        /*
         * Where the per-account billing-currency lock lives. An account's currency is
         * fixed by its FIRST finalized invoice and is thereafter one-way — the lock is
         * keyed on the billing account alone and is independent of any payment method
         * (it survives a card being added or removed). `memory` (default, zero-config)
         * · `database` (run the migration; pair with a durable invoice number sequence
         * on the same connection so the first-finalize stamp and the invoice commit
         * together, and a concurrent first-finalize resolves to one currency).
         */
        'currency_lock_store' => env('CBOX_BILLING_CURRENCY_LOCK_STORE', 'memory'),
    ],

    /*
     * Payment collection, including the dunning / delinquency policy.
     */
    'payment' => [

        /*
         * Dunning knobs — how an unpaid, past-due account is chased and, ultimately,
         * suspended. Suspension gates ACCESS only (it flips the account's standing); it
         * never touches credit balances or the ledger. Restore is deliberately strict:
         * an account is only lifted back to access once ALL its debt is cleared and none
         * is written off (uncollectible), so paying part of a bill never silently
         * reopens the door.
         */
        'dunning' => [

            /*
             * Once the oldest past-due invoice is this many days old — AND the minimum
             * reminders have gone out — the account is escalated to suspension.
             */
            'max_delinquency_days' => (int) env('CBOX_BILLING_DUNNING_MAX_DAYS', 30),

            /*
             * How many reminders must be sent BEFORE suspension is allowed. Even past the
             * day threshold, the account keeps receiving notices until this many have
             * gone out — an account is never suspended un-warned.
             */
            'min_notice_count' => (int) env('CBOX_BILLING_DUNNING_MIN_NOTICES', 3),

            /*
             * The minimum gap, in days, between two reminders (the cadence).
             */
            'notice_frequency_days' => (int) env('CBOX_BILLING_DUNNING_NOTICE_DAYS', 7),

            /*
             * Grace window: an invoice less than this many hours past its due instant is
             * a just-missed payment and is NOT dunned. Governs notices/suspension only —
             * any open invoice, however fresh, still counts as debt that keeps a
             * suspended account suspended.
             */
            'grace_hours' => (int) env('CBOX_BILLING_DUNNING_GRACE_HOURS', 24),
        ],
    ],

    /*
     * Real-time usage metering + the app-local enforcement hot path.
     */
    'metering' => [

        /*
         * Allowance leasing. A node leases a slice of the organization's remaining
         * allowance and enforces against it locally, refilling when depleted.
         * `default_size` is how many units to request per refill; larger = fewer
         * round-trips to billing but more units potentially stranded on a node.
         */
        'lease' => [
            'default_size' => (int) env('CBOX_BILLING_LEASE_SIZE', 100),
            'prefix' => env('CBOX_BILLING_LEASE_PREFIX', 'cbox-billing:lease:'),
        ],

        /*
         * Enforcement failure policy (ADR-0004). Enforcement fails CLOSED on
         * semantics (an unknown/disabled meter or an exhausted allowance is always
         * denied) but must decide what to do when a DEPENDENCY is unavailable —
         * store/cache down, lock/lease timeout, transport error — and no decision can
         * be reached. `infra_failure` controls that: `allow` (default) fails OPEN so a
         * blip does not throttle paid traffic (the durable ledger reconciles the
         * truth), while `deny` fails CLOSED for strict tenants. Either way a signal is
         * emitted so operators see the infra path fire.
         */
        'enforcement' => [
            'infra_failure' => env('CBOX_BILLING_INFRA_FAILURE', 'allow'),
        ],

        /*
         * How long billing keeps a usage event's dedup key so re-delivered events
         * are counted exactly once. Late duplicates outside this window can slip
         * through and are caught by reconciliation instead.
         */
        'dedup_window_days' => (int) env('CBOX_BILLING_DEDUP_WINDOW_DAYS', 32),

        /*
         * Where the immutable usage event log (the metering source of truth) is
         * stored. `memory` (default, zero-config) · `database` (MySQL/Postgres — run
         * the migration; fine for small/most deployments) · a ClickHouse adapter
         * binds the EventLog contract for event-heavy scale. ClickHouse is optional.
         */
        'event_log' => env('CBOX_BILLING_EVENT_LOG', 'memory'),
    ],

    /*
     * Entitlement projection + plan-wide rollout.
     */
    'entitlement' => [

        /*
         * Bulk entitlement rollout. When a plan-wide entitlement change rolls out to a
         * cohort, orgs WITHOUT overrides are applied in chunks — each chunk one atomic
         * transaction — and no per-org cache-bust is fired (invalidation rides the
         * hot-path cache TTL), so a 100k-org plan does not storm the cache. `chunk_size`
         * is how many orgs are written per transaction: larger = fewer transactions but a
         * bigger rollback unit and more rows locked at once. Orgs WITH overrides bypass
         * this path — they are written individually and cache-busted immediately.
         */
        'rollout' => [
            'chunk_size' => (int) env('CBOX_BILLING_ROLLOUT_CHUNK_SIZE', 500),
        ],
    ],

    /*
     * Convergent reconciliation (ADR-0003). The reconciler closes the gap between the
     * fast hot-path counter and the durable ledger by posting a cumulative delta
     * against a per-entity checkpoint — never replaying events.
     */
    'reconciliation' => [

        /*
         * Ingest-lag clamp. Usage is only reconciled up to `now − ingest_lag`, so
         * in-flight events that have not fully landed in the durable log are not
         * counted early — they are caught on a later cycle once the clamp advances
         * past them. Size this to the async pipeline's worst-case landing delay.
         */
        'ingest_lag_seconds' => (int) env('CBOX_BILLING_RECONCILE_INGEST_LAG', 60),

        /*
         * Reconcile window. Usage older than `now − window` is attributed to an
         * `aged_out` bucket instead of the live meter bucket — never silently dropped.
         * A far-late straggler still lands in the ledger, kept separate for audit.
         */
        'window_days' => (int) env('CBOX_BILLING_RECONCILE_WINDOW_DAYS', 32),

        /*
         * The denomination usage deltas are carried into the ledger in — the allowance
         * unit the derived hot-path balance reads (ADR-0008), not a priced amount. Any
         * currency the host's ledger is registered for.
         */
        'currency' => env('CBOX_BILLING_RECONCILE_CURRENCY', 'EUR'),

        /*
         * Where per-entity checkpoints live. `memory` (default, zero-config) ·
         * `database` (run the migration; pair with a database ledger on the same
         * connection so the delta post and the checkpoint advance share one
         * transaction).
         */
        'checkpoint_store' => env('CBOX_BILLING_RECONCILE_CHECKPOINT', 'memory'),
    ],

];
