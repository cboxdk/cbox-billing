<?php

declare(strict_types=1);

use Cbox\License\Capabilities;

/**
 * Parse a comma-separated env value into a de-duplicated list of trimmed, non-empty strings.
 * Used by the console operator allowlists; an unset/blank value yields an empty list (which
 * makes the console fail closed — see the `console` section below).
 *
 * @return list<string>
 */
$csv = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [];
    }

    $out = [];
    foreach (explode(',', $value) as $item) {
        $item = trim($item);
        if ($item !== '' && ! in_array($item, $out, true)) {
            $out[] = $item;
        }
    }

    return $out;
};

return [

    /*
     * The app's default billing currency (ISO 4217). Used only as the last-resort
     * fallback when an account has neither finalized an invoice (no currency lock) nor
     * chosen a currency at signup. Once an account transacts, the currency lock is the
     * authority — this value never overrides it.
     */
    'default_currency' => env('CBOX_BILLING_DEFAULT_CURRENCY', 'DKK'),

    /*
     * The provider console's COARSE authorization boundary (SEC-1).
     *
     * The console is for the host's INTERNAL operators, who live in a dedicated operator
     * organization on Cbox ID (e.g. the `cbox` tenant). A valid Cbox ID session alone is NOT
     * enough to reach it — Cbox ID is a live, multi-tenant issuer that also holds customer and
     * end-user accounts, so "can complete OIDC" must never mean "administers the provider".
     * Only a session whose identity belongs to an allowlisted operator organization (or an
     * explicitly allowlisted subject, for break-glass) may reach ANY console route.
     *
     * DENY-BY-DEFAULT / FAIL-CLOSED: when BOTH lists are empty the console denies every
     * session and logs an actionable warning telling the operator to set
     * `CBOX_BILLING_OPERATOR_ORGS`. This is orthogonal to — and coarser than — the per-
     * permission RBAC below: this gate decides WHETHER a session may touch the console at
     * all; RBAC (once Cbox ID emits `permissions`) refines access WITHIN the operator org.
     *
     * Enforced by {@see \App\Http\Middleware\EnsureOperator} on the whole `auth.cbox` console
     * group. The management/enforcement API (token-authed, org-scoped) and the customer portal
     * (signed-token) have their own correct scoping and are deliberately NOT gated here.
     *
     * `operator_orgs` — comma-separated Cbox ID org ids whose members may operate the console.
     * `operator_subjects` — comma-separated Cbox ID `sub`s allowlisted individually (break-glass).
     */
    'console' => [
        'operator_orgs' => $csv(env('CBOX_BILLING_OPERATOR_ORGS')),
        'operator_subjects' => $csv(env('CBOX_BILLING_OPERATOR_SUBJECTS')),
    ],

    /*
     * Console RBAC enforcement (the `billing.permission:<feature:action>` middleware).
     *
     * IMPORTANT — honest rollout. Cbox ID does not yet emit a `permissions` (or `roles`)
     * claim in the id_token or userinfo, so this app has no per-caller permission signal to
     * enforce against today. The middleware is therefore INERT by default: with `enforce`
     * false it resolves whatever permissions the principal carries onto the request (for
     * downstream use) but NEVER blocks — so it can never lock the single operator surface
     * out before the signal exists.
     *
     * Flip `enforce` to true ONLY once a coordinated Cbox ID release emits the claim AND you
     * intend strict deny-by-default: a caller then needs the exact slug a route declares, or
     * gets a 403. See docs/identity/rbac-manifest.md → Enforcement.
     */
    'rbac' => [
        'enforce' => (bool) env('CBOX_ID_RBAC_ENFORCE', false),
    ],

    /*
     * The organization credit wallet. Under ADR-0013 a plan's included allowances and
     * credits are one unified pool-grant model burned down in one order, so credit
     * balances must survive a restart: `database` binds the durable {@see DatabaseWallet}
     * (one row per grant lot, run the migration) instead of the in-memory reference.
     * `memory` (zero-config) · `database` (durable, the app default).
     */
    'wallet' => [
        'store' => env('CBOX_BILLING_WALLET_STORE', 'database'),
    ],

    /*
     * Cross-family plan transitions (ADR-0010). Plans in the same family (a product's
     * plans share their product key as their family) may be switched between freely;
     * a move ACROSS families is deny-by-default and only permitted along an explicitly
     * declared edge here. Each edge is `{from, to, guidance?, carry_over?}` in family
     * keys — `carry_over` keeps the outgoing allotment instead of the reset default.
     * Empty by default: the demo catalog is a single family, so every ladder move is a
     * same-family change and needs no edge.
     *
     * @var list<array{from: string, to: string, guidance?: string, carry_over?: bool}>
     */
    'transitions' => [],

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
     * Subscription renewal. `reminder_lead_days` is how many days ahead of a term renewal
     * the renewal-reminder email goes out — the daily renewal pass fires it once, on the day
     * the subscription's period end crosses into this lead window (never again inside it).
     */
    'renewal' => [
        'reminder_lead_days' => (int) env('CBOX_BILLING_RENEWAL_REMINDER_DAYS', 7),
    ],

    /*
     * Seats — the purchased + explicitly-assigned model.
     *
     * PURCHASED Full seats ARE the subscription's seat quantity: what the plan's per-seat
     * price bills, and the ONLY billing driver. An admin buys/releases them explicitly
     * (console/API → the engine's changeQuantity), never a membership event. ASSIGNMENT is
     * app-side: each purchased Full seat is handed to a specific eligible member; a member in
     * the access mirror WITHOUT an assignment is Light. The invariant is assigned ≤ purchased.
     *
     * `types` describes the seat types for labels/reporting and to keep the shape open: Full
     * is billable at the plan's per-seat price; Light is `billable: false` (FREE today). The
     * config is deliberately priced-Light-shaped — a `price_minor`/dedicated Light price can
     * be added later WITHOUT a breaking change; Light stays free until then.
     */
    'seats' => [

        'types' => [
            'full' => [
                'label' => 'Full',
                // Billed at the plan's per-seat price (the subscription quantity).
                'billable' => true,
            ],
            'light' => [
                'label' => 'Light',
                // Free today. Kept config-shaped so a priced Light tier is expressible later
                // (add a price here) without reshaping the model or the reporting.
                'billable' => false,
            ],
        ],

        /*
         * Auto-assign mode. When true, a `member_added` / `role.assigned` whose role is in
         * `auto_assign_roles` is given a FREE purchased seat automatically (source `auto`),
         * but only when one is free — it never auto-buys and never exceeds the purchased cap;
         * otherwise the member stays Light. When false (the default), membership only updates
         * eligibility and every assignment is manual.
         */
        'auto_assign' => (bool) env('CBOX_BILLING_SEAT_AUTO_ASSIGN', false),

        /*
         * The roles auto-assign considers seat-worthy. A member whose (new) role leaves this
         * set has any AUTO-sourced seat released; a MANUAL seat is never auto-released.
         *
         * @var list<string>
         */
        'auto_assign_roles' => ['billing-admin', 'billing-operator'],
    ],

    /*
     * Free trials. A subscribe-with-trial opens the subscription `Trialing` (serving its
     * plan, charging nothing) until `trial_ends_at`, when the scheduled
     * `billing:convert-trials` pass converts it to a paying `Active` (first charge).
     */
    'trial' => [

        /*
         * The default trial length, in days, when a subscribe-with-trial does not specify
         * its own. The trial ends this many days after the subscription opens.
         */
        'default_days' => (int) env('CBOX_BILLING_TRIAL_DAYS', 14),

        /*
         * How many days ahead of a trial's conversion the trial-ending reminder email goes
         * out — the daily convert pass fires it once, on the day the trial end crosses into
         * this lead window (never again inside it).
         */
        'reminder_lead_days' => (int) env('CBOX_BILLING_TRIAL_REMINDER_DAYS', 3),

        /*
         * Whether a due trial requires a payment method on file before it converts. When
         * true, a trial with no vaulted method at conversion is NOT charged — it takes the
         * `no_payment_method_action` below instead. When false (the default, matching a
         * manual / out-of-band gateway that vaults nothing), a due trial always converts and
         * its first invoice is collected on the ordinary charge/renewal path.
         */
        'require_payment_method' => env('CBOX_BILLING_TRIAL_REQUIRE_PM', false),

        /*
         * What to do with a due trial that has no payment method when one is required:
         * `cancel` (end the subscription — deny-by-default) or `pause` (suspend it so the
         * customer can add a method and resume). Only consulted when
         * `require_payment_method` is true.
         */
        'no_payment_method_action' => env('CBOX_BILLING_TRIAL_NO_PM_ACTION', 'cancel'),
    ],

    /*
     * Retention. Captured churn reasons live in the `subscription_cancellations` log; this
     * governs win-back.
     */
    'retention' => [

        /*
         * How many days after a subscription is canceled it may still be reactivated
         * (re-subscribed to the same plan) through the win-back path. A cancellation older
         * than this is a fresh subscribe, not a reactivation.
         */
        'reactivation_window_days' => (int) env('CBOX_BILLING_REACTIVATION_WINDOW_DAYS', 30),
    ],

    /*
     * Plan retirement (ADR-0016): a plan's sunset flow. When a plan is marked retiring the
     * migration pass moves its subscribers off it at renewal; ahead of the cutoff, affected
     * subscribers are reminded this many days in advance (once per subscription per window).
     */
    'retirement' => [
        'reminder_lead_days' => (int) env('CBOX_BILLING_RETIREMENT_REMINDER_LEAD_DAYS', 14),
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

        /*
         * Smart-retry dunning — how a FAILED renewal charge is chased. On a failure the
         * subscription moves to the engine's `PastDue` state and the invoice is retried on
         * the gateway on this backoff, a payment-failed email going out each attempt. A
         * retry that settles recovers the subscription to `Active` (+ receipt); an exhausted
         * schedule runs the `terminal_action`. This is the money-collection counterpart to
         * the access-gating `dunning` policy above — the two run independently.
         */
        'retry' => [

            /*
             * The backoff schedule as day-offsets from the initial failure: attempt N fires
             * `schedule[N-1]` days after the charge first failed. The number of entries is
             * the maximum number of retries — once the last offset's attempt fails, the
             * schedule is exhausted. Default: retry on days 1, 3, 5 and 7.
             *
             * @var list<int>
             */
            'schedule' => [1, 3, 5, 7],

            /*
             * What to do when the retry schedule is exhausted without recovering the
             * payment: `cancel` (cancel the subscription immediately — the engine's
             * forfeiture-on-transition fires) or `none` (leave it `PastDue` for manual
             * handling; the access-gating dunning pass still governs suspension).
             */
            'terminal_action' => env('CBOX_BILLING_RETRY_TERMINAL_ACTION', 'cancel'),
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

    /*
     * The enforcement HTTP API the `cboxdk/laravel-billing-client` SDK talks to.
     */
    'api' => [

        /*
         * A bearer token that authenticates as an OPERATOR (any org) without a database
         * row — the simplest possible auth for a single-tenant or bootstrapping setup.
         * Leave null in multi-tenant deployments and issue per-org `api_tokens` instead;
         * the authenticator is a swappable contract either way (deny-by-default when
         * neither a static token nor a matching row authenticates the request).
         */
        'static_token' => env('CBOX_BILLING_API_TOKEN'),

        /*
         * How long a leased slice and a reservation live before they are considered
         * abandoned. The SDK re-leases/renews within this window; an abandoned slice is
         * reclaimed by the central budget on expiry (seconds).
         */
        'lease_ttl_seconds' => (int) env('CBOX_BILLING_LEASE_TTL', 300),
        'reservation_ttl_seconds' => (int) env('CBOX_BILLING_RESERVATION_TTL', 300),

        /*
         * `last_used_at` on an api_token is a coarse "recently seen" signal, not an audit log.
         * On the SDK hot path a per-call UPDATE (and its row lock) is churn for no new
         * information, so the stamp is throttled: it is only rewritten when the previous stamp
         * is older than this many seconds (PERF-5).
         */
        'last_used_throttle_seconds' => (int) env('CBOX_BILLING_TOKEN_LAST_USED_THROTTLE', 300),
    ],

    /*
     * Request-idempotency records (the `Idempotency-Key` replay store). These rows can hold a
     * captured 2xx response body, so they are pruned on a schedule (`billing:prune-idempotency`,
     * registered hourly) — never kept indefinitely (SEC-2).
     *
     *  - `retention_hours` — a completed record replays a retry within this window, then is
     *    dropped. Well past any realistic client retry horizon, short enough not to hoard.
     *  - `stale_after_minutes` — a CLAIMED-but-never-completed record (`response_status` null,
     *    L1) blocks its key forever if a first attempt died after claiming; it is reaped once
     *    older than this, so a genuine retry can re-claim the key. Must comfortably exceed the
     *    longest legitimate request duration.
     */
    'idempotency' => [
        'retention_hours' => (int) env('CBOX_BILLING_IDEMPOTENCY_RETENTION_HOURS', 72),
        'stale_after_minutes' => (int) env('CBOX_BILLING_IDEMPOTENCY_STALE_MINUTES', 60),
    ],

    /*
     * Per-token API rate limits (requests per minute), keyed on the caller's bearer token
     * (the IP is the fallback for an unauthenticated request). Two tiers plus the webhook:
     *
     *  - `enforcement` — the SDK hot path (reserve / commit / usage / leases / entitlements).
     *    It runs on every metered operation, so it gets the higher ceiling.
     *  - `management` — the self-service surface (subscriptions, payment intents, licenses).
     *    Human-paced and mutating, so it gets a lower ceiling.
     *  - `webhook` — inbound settlement callbacks from the payment gateway.
     *
     * These are the named limiters registered in {@see \App\Providers\AppServiceProvider}
     * and applied as `throttle:cbox-*` on the route groups. The unauthenticated activation
     * heartbeat keeps its own inline `throttle:30,1`.
     */
    'rate_limits' => [
        'enforcement' => (int) env('CBOX_BILLING_THROTTLE_ENFORCEMENT', 600),
        'management' => (int) env('CBOX_BILLING_THROTTLE_MANAGEMENT', 60),
        'webhook' => (int) env('CBOX_BILLING_THROTTLE_WEBHOOK', 120),
    ],

    /*
     * The selling entities of record (multi-entity routing). Each entity issues invoices
     * under its own legal identity, tax registrations and per-entity number sequence; the
     * entity that issues the invoice drives the tax outcome. Values here are synthetic
     * demo identifiers — replace them with the real registered numbers in production.
     */
    'seller' => [

        'default' => env('CBOX_BILLING_SELLER', 'cbox-dk'),

        'entities' => [
            'cbox-dk' => [
                'legal_name' => 'Cbox',
                'registration_number' => 'DK00000000',
                'establishment' => 'DK',
                'currency' => 'DKK',
                'invoice_prefix' => 'CBOX-DK',
                'tax_registrations' => [
                    ['country' => 'DK', 'number' => 'DK00000000'],
                ],
            ],
        ],
    ],

    /*
     * Payment gateways the console surfaces in Settings. The `manual` gateway is always
     * present — it settles out of band via a signed settlement webhook (see `webhook`
     * below), and is "connected" once a webhook secret is configured. Provider adapters
     * (Stripe, Mollie, …) bind the same PaysInvoices / WebhookVerifier contracts; they
     * are listed here as available and flip to connected when their credentials are set.
     */
    'gateways' => [
        'manual' => [
            'name' => 'Manual / bank transfer',
            'mode' => 'signed-webhook',
            'connected' => env('CBOX_BILLING_WEBHOOK_SECRET') !== null,
        ],
        'stripe' => [
            'name' => 'Stripe',
            'mode' => 'adapter',
            'connected' => env('STRIPE_SECRET') !== null,
        ],
        'mollie' => [
            'name' => 'Mollie',
            'mode' => 'adapter',
            'connected' => env('MOLLIE_KEY') !== null,
        ],
    ],

    /*
     * Payment webhook verification. The manual gateway settles out of band; the operator
     * (or a payment provider adapter) posts a settlement webhook signed with a shared
     * secret. Verification is deny-by-default: with no secret configured the verifier
     * refuses every payload rather than trusting it.
     */
    'webhook' => [
        'secret' => env('CBOX_BILLING_WEBHOOK_SECRET'),
        'signature_header' => env('CBOX_BILLING_WEBHOOK_SIGNATURE_HEADER', 'X-Cbox-Signature'),
    ],

    /*
     * Hosted checkout + customer portal (ADR-0009 Path A). A checkout- or portal-session
     * is addressed by an opaque, non-guessable token carried in the hosted page URL — the
     * token, not the provider auth gate, authorizes the page. `session_ttl_minutes` bounds
     * how long a pending token stays usable before it is stamped expired.
     */
    'hosted' => [
        'session_ttl_minutes' => (int) env('CBOX_BILLING_HOSTED_SESSION_TTL', 30),

        /*
         * Where a hosted checkout returns once its payment settles. Used as the return
         * URL for the pre-built upgrade deep-links an enforcement denial carries (#52) —
         * the customer lands back here after unlocking the required plan. Defaults to the
         * app root.
         */
        'upgrade_return_url' => env('CBOX_BILLING_UPGRADE_RETURN_URL'),
    ],

    /*
     * On-prem / self-hosted licensing (the issuer side). Billing mints a signed,
     * offline-verifiable license from a licensable plan; a self-hosted cbox-id
     * deployment bundles the PUBLIC key and verifies the artifact with no call home.
     *
     * The engine's licensing module reads `profiles` (the licensable-plan map) and
     * `grace_seconds` (the offline validity buffer) from this section; the app's
     * LicensingServiceProvider reads `signing_key` / `public_key` to bind the crypto
     * core, and `validity_days` / `clock_skew_seconds` to size issued windows.
     */
    'licensing' => [

        /*
         * The base64 Ed25519 PRIVATE key that signs licenses and revocation lists — the
         * issuer secret. NEVER commit it and NEVER log it: `.env` is gitignored, and
         * `.env.example` carries only an empty placeholder. Generate a pair with
         * `php artisan billing:license-keygen`. When unset, licensing is inert — the app
         * still boots, and only an actual mint/publish surfaces a clear operator error.
         */
        'signing_key' => env('CBOX_LICENSE_SIGNING_KEY'),

        /*
         * The base64 Ed25519 PUBLIC key — safe to display and distribute. Operators
         * bundle it in the self-hosted cbox-id deployment so it can verify licenses
         * offline. Shown in the Licenses settings panel for air-gapped hand-off.
         */
        'public_key' => env('CBOX_LICENSE_PUBLIC_KEY'),

        /*
         * The consume-license THIS deployment installs to unlock its OWN commercial
         * plugins — the flip side of the issuer keys above. `signing_key`/`public_key`
         * are the ISSUER pair this app uses to sign licenses FOR customers; `consume_key`
         * is a license (signed by that same issuer) that a composed deployment installs
         * so its bundled paid plugins light up. When set, the LicensingServiceProvider
         * verifies it offline against `public_key` and binds a license-backed
         * CapabilityGate; when empty, the gate denies by default and the deployment runs
         * the free tier. NEVER commit a real key — `.env` is gitignored and `.env.example`
         * carries only an empty placeholder.
         */
        'consume_key' => env('CBOX_BILLING_LICENSE_KEY'),

        /*
         * This deployment's stable identity, matched against the consume-license's
         * deployment binding when verifying `consume_key`. A mismatch — or an absent id —
         * unlocks nothing (deny-by-default), so a license minted for one deployment cannot
         * light up another.
         */
        'deployment_id' => env('CBOX_BILLING_DEPLOYMENT_ID'),

        /*
         * How long a freshly-issued license lasts when its window is NOT derived from a
         * subscription's paid period (the console/API issue flow default). A
         * subscription-driven reissue instead tracks the paid-period end via the engine's
         * SubscriptionLicensePolicy (period end + the grace buffer below).
         */
        'validity_days' => (int) env('CBOX_LICENSE_VALIDITY_DAYS', 365),

        /*
         * The offline grace buffer added past a license's `expiresAt`. It covers the lag
         * between a renewal being paid and the new artifact being pulled by the
         * deployment, without ever handing out a license that outlives the paid period by
         * more than this. Exposed to the engine's SubscriptionLicensePolicy as
         * `grace_seconds`; the verifier honours the same window.
         */
        'grace_days' => (int) env('CBOX_LICENSE_GRACE_DAYS', 14),
        'grace_seconds' => ((int) env('CBOX_LICENSE_GRACE_DAYS', 14)) * 86_400,

        /*
         * Clock-skew tolerance the verifier applies to the not-before / expiry checks, so
         * a small wall-clock drift on an air-gapped host does not spuriously invalidate a
         * license. Advisory to the issuer; enforced by the verifier deployment.
         */
        'clock_skew_seconds' => (int) env('CBOX_LICENSE_CLOCK_SKEW', 60),

        /*
         * The licensable-plan map — the issuer-side policy that turns a paid plan into a
         * license's contents. Deny-by-default: a plan absent here is NOT licensable and
         * can never be minted (a self-serve plan ships no offline artifact). Each entry is
         * `entitlements` (opaque {@see Capabilities} keys the license unlocks) and `limits`
         * (the quantitative ceilings: organizations / seats / environments; omit or null a
         * dimension for "unlimited"). Keyed by plan id — the same key the subscription's
         * plan carries, so an active subscription on a licensable plan can be minted.
         */
        'profiles' => [
            'enterprise-onprem' => [
                'entitlements' => [
                    Capabilities::MULTI_TENANT_PLATFORM,
                    Capabilities::SSO,
                    Capabilities::SAML,
                    Capabilities::SCIM,
                    Capabilities::ANALYTICS,
                    Capabilities::COMPLIANCE,
                    Capabilities::SUPPORT,
                ],
                'limits' => [
                    'organizations' => 50,
                    'seats' => 500,
                    'environments' => 5,
                ],
            ],
            'team-onprem' => [
                'entitlements' => [
                    Capabilities::SSO,
                    Capabilities::SCIM,
                    Capabilities::SUPPORT,
                ],
                'limits' => [
                    'organizations' => 5,
                    'seats' => 50,
                    'environments' => 2,
                ],
            ],
        ],
    ],

];
