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

/**
 * Parse an env value into an int, or null when it is unset/blank/the literal "null" — so an
 * operator can DISABLE a numeric threshold (the CPQ approval amount gate) rather than only
 * lower it. A present numeric value is cast to int.
 */
$intOrNull = static function (int|string|null $value): ?int {
    if ($value === null || (is_string($value) && (trim($value) === '' || strtolower(trim($value)) === 'null'))) {
        return null;
    }

    return (int) $value;
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
     * Consolidated reporting (multi-entity + multi-currency). The ledger always stays in each
     * transaction's own currency; these settings drive only the reporting overlay that
     * normalizes the whole book to one currency for a single consolidated MRR/ARR view.
     *
     *  - `currency` — the reporting currency every entity's/currency's MRR is converted to. Null
     *    (the default) means "use the default selling entity's currency", so a single-entity
     *    deployment needs no config. A console operator can pick another currency per request.
     *  - `fx.as_of` — the FX as-of basis for a period bridge: `period_end` (the default, the most
     *    reproducible — a closed period's rate never moves) or `today` (spot). The per-currency
     *    table always shows the exact date of the rate row applied, so the basis is auditable.
     */
    'reporting' => [
        'currency' => env('CBOX_BILLING_REPORTING_CURRENCY'),
        'fx' => [
            'as_of' => env('CBOX_BILLING_REPORTING_FX_ASOF', 'period_end'),
        ],
    ],

    /*
     * Foreign-exchange rate ingestion for consolidated reporting. Rates are NEVER fabricated —
     * they come from a cited, public feed or an operator/treasury override, and an unavailable
     * pair is reported honestly as "rate unavailable".
     *
     *  - `sources` — the rate sources `fx:refresh` pulls, in order. `ecb` is the European Central
     *    Bank euro foreign-exchange reference-rate feed (free, public, citable); `override` is the
     *    operator overrides below. Drop a source to disable it.
     *  - `ecb.url` — the ECB daily reference-rate XML. Source: European Central Bank, "Euro foreign
     *    exchange reference rates" (see docs/reporting/fx-rates.md). Base EUR; non-EUR cross-rates
     *    are derived via the EUR pivot at read time, not fetched.
     *  - `overrides` — operator/treasury-fixed directed rates (`1 base = rate quote`) for a pair ECB
     *    does not publish, or to pin an internal rate. Each entry: `{date?, base, quote, rate}`
     *    (`date` defaults to today). An override supersedes ECB on the same (date, pair). The
     *    console can author more of these into the `fx_rates` table.
     */
    'fx' => [
        'sources' => ['ecb', 'override'],
        'ecb' => [
            'url' => env('CBOX_BILLING_FX_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'),
        ],
        'overrides' => [
            // ['date' => '2026-07-20', 'base' => 'USD', 'quote' => 'XOF', 'rate' => '600.0'],
        ],
    ],

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
     * CPQ — sales quoting + contracts (Wave 5). A rep authors a quote, it is (optionally)
     * approved above a threshold, sent to the customer as a branded order form, accepted by
     * e-signature-by-acceptance, and provisions a subscription through the engine.
     *
     * `approval` — the deal-desk threshold. A quote whose first-invoice gross (in the seller's
     *   reporting/default currency, minor units) is at or above `amount_minor`, OR whose largest
     *   line discount is at or above `discount_percent`, must be approved by an operator holding
     *   `quotes:approve` before it can be sent. `amount_minor => null` disables the amount gate
     *   (only the discount gate applies); both null → every quote is auto-approvable.
     * `valid_days` — the default validity window stamped on a new quote's `valid_until`.
     * `token_bytes` — entropy (raw bytes) of the opaque order-form URL token.
     * `number_prefix` — the human quote-number prefix (`Q-00001`).
     * `signature.provider` — the e-signature seam. `null` (default) is in-house
     *   e-sign-by-acceptance (typed full name + explicit agree + captured timestamp/IP → an
     *   immutable acceptance record). A host binds a real provider (DocuSign, etc.) to the
     *   {@see \App\Billing\Cpq\Contracts\CapturesSignature} contract; this app ships ONLY the
     *   null provider and does NOT fabricate a third-party integration.
     */
    'quotes' => [
        'approval' => [
            'amount_minor' => $intOrNull(env('CBOX_BILLING_QUOTE_APPROVAL_AMOUNT_MINOR', 5_000_00)),
            'discount_percent' => (int) env('CBOX_BILLING_QUOTE_APPROVAL_DISCOUNT_PERCENT', 25),
        ],
        'valid_days' => (int) env('CBOX_BILLING_QUOTE_VALID_DAYS', 30),
        'token_bytes' => (int) env('CBOX_BILLING_QUOTE_TOKEN_BYTES', 32),
        'number_prefix' => env('CBOX_BILLING_QUOTE_NUMBER_PREFIX', 'Q-'),
        'signature' => [
            'provider' => env('CBOX_BILLING_QUOTE_SIGNATURE_PROVIDER', 'null'),
        ],
    ],

    /*
     * Approvals — the general maker-checker (two-person rule) engine. A sensitive operator
     * mutation that trips the policy is HELD as an `approval_requests` row and does NOT take
     * effect until a DIFFERENT operator approves it, at which point the captured action runs
     * exactly once. It generalizes the CPQ deal-desk approval to every money-sensitive action.
     *
     * `permission` — the `feature:action` slug a checker needs to decide (approve/reject). Gated
     *   by the same flag-held `billing.permission` middleware as the rest of the console.
     * `expire_after_days` — a pending request auto-expires after this many days (null = never);
     *   an expired request never executes.
     * `actions.<type>` — per action-type policy:
     *   - `enabled` — DEFAULT FALSE. Disabled means the action executes directly, exactly as
     *     before (no regression). Turn it on to route that action through the gate.
     *   - `threshold_minor` — when enabled, an amount at or above this floor (minor units) needs
     *     approval; null means "no amount gate" → every invocation needs approval. Actions with
     *     no money dimension (customer.suspend, plan.archive) ignore the floor and always hold
     *     when enabled.
     *   - `required` — how many DISTINCT operators must approve before it runs (the M in M-of-N;
     *     default 1). The maker is never counted — self-approval is refused.
     *
     * Every action type ships DISABLED so an unconfigured deployment behaves exactly as today.
     */
    'approvals' => [
        'permission' => env('CBOX_BILLING_APPROVALS_PERMISSION', 'approvals:decide'),
        'expire_after_days' => $intOrNull(env('CBOX_BILLING_APPROVALS_EXPIRE_DAYS')),
        'actions' => [
            'invoice.refund' => [
                'enabled' => (bool) env('CBOX_BILLING_APPROVE_REFUND', false),
                'threshold_minor' => $intOrNull(env('CBOX_BILLING_APPROVE_REFUND_THRESHOLD_MINOR')),
                'required' => (int) env('CBOX_BILLING_APPROVE_REFUND_REQUIRED', 1),
            ],
            'wallet.adjust' => [
                'enabled' => (bool) env('CBOX_BILLING_APPROVE_WALLET', false),
                'threshold_minor' => $intOrNull(env('CBOX_BILLING_APPROVE_WALLET_THRESHOLD_MINOR')),
                'required' => (int) env('CBOX_BILLING_APPROVE_WALLET_REQUIRED', 1),
            ],
            'customer.suspend' => [
                'enabled' => (bool) env('CBOX_BILLING_APPROVE_SUSPEND', false),
                'threshold_minor' => null,
                'required' => (int) env('CBOX_BILLING_APPROVE_SUSPEND_REQUIRED', 1),
            ],
            'plan.archive' => [
                'enabled' => (bool) env('CBOX_BILLING_APPROVE_PLAN_ARCHIVE', false),
                'threshold_minor' => null,
                'required' => (int) env('CBOX_BILLING_APPROVE_PLAN_ARCHIVE_REQUIRED', 1),
            ],
            /*
             * GDPR right-to-be-forgotten erasure hard-deletes the subject's certificate documents
             * (irreversible), so — unlike the money actions above — it defaults to ENABLED: a DSAR
             * erase always needs a second operator (maker-checker), never a single-operator destroy.
             * An operator can still opt out via CBOX_BILLING_APPROVE_DATA_ERASE=false.
             */
            'data.erase' => [
                'enabled' => filter_var(env('CBOX_BILLING_APPROVE_DATA_ERASE', true), FILTER_VALIDATE_BOOL),
                'threshold_minor' => null,
                'required' => (int) env('CBOX_BILLING_APPROVE_DATA_ERASE_REQUIRED', 1),
            ],
        ],
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
             * The BASE backoff schedule as day-offsets from the initial failure: attempt N
             * fires `schedule[N-1]` days after the charge first failed. Adaptive dunning
             * (`dunning.strategies` below) uses this as the curve every decline category
             * inherits until it opts into its own — so a deployment that never tunes a category
             * keeps exactly this schedule, and a category with a specific curve (insufficient
             * funds, do-not-honor) overrides it. The number of entries is the retry ceiling.
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
     * Data export + warehouse sinks. The console/API stream any dataset to CSV or NDJSON, and a
     * scheduled `warehouse:sync` stages incremental partitions to configured object-store sinks
     * (the way Snowflake/BigQuery/Redshift ingest at scale) alongside copy-paste load manifests.
     *
     *  - `chunk_size` — the streaming chunk size; every export reads the database in chunks of
     *    this many rows and never materialises the whole dataset, so memory is bounded regardless
     *    of table size. Lower it on a memory-tight host; raise it to trade memory for fewer round
     *    trips.
     *  - `default_disk` — the object-store disk a newly-created sink defaults to (any disk in
     *    config/filesystems.php; `s3` for a real deployment). The staged-file path is fully
     *    functional with just that disk's credentials — no warehouse SDK is bundled.
     */
    'export' => [
        'chunk_size' => (int) env('CBOX_BILLING_EXPORT_CHUNK_SIZE', 500),
        'default_disk' => env('CBOX_BILLING_EXPORT_DISK', 's3'),

        /*
         * The filesystem disks a warehouse sink is ALLOWED to stage to (deny-by-default). A sink's
         * `disk` is operator input that becomes `Storage::disk($disk)`, so it is allow-listed to
         * the object-store disks intended for export rather than accepting any disk name in
         * config/filesystems.php (e.g. a local/private disk). Comma-separated disk names.
         *
         * @var list<string>
         */
        'allowed_disks' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('CBOX_BILLING_EXPORT_ALLOWED_DISKS', 's3'))),
            static fn (string $disk): bool => $disk !== '',
        )),

        /*
         * The URI schemes a sink's `external_base` (the warehouse-addressable staged location) may
         * use. Deny-by-default: an `external_base` with any other scheme is refused, so a manifest
         * can never phrase a load statement against a `file://`/`http://` or otherwise unexpected
         * location.
         *
         * @var list<string>
         */
        'warehouse_uri_schemes' => ['s3', 's3a', 'gs', 'gcs', 'azure', 'abfss', 'https'],
    ],

    /*
     * Adaptive dunning — the decline-code-aware recovery strategy that drives the smart-retry
     * schedule above. Where the base `payment.retry.schedule` is one curve for every failure,
     * this branches the recovery on WHY the charge declined: each failed charge's gateway
     * decline code is classified into a category (hard · insufficient-funds · recoverable ·
     * try-again-later · needs-action) and a per-category strategy chooses the retry count,
     * backoff curve and timing heuristics. The goal is Recurly/Stripe-Smart-Retries-grade
     * recovery: don't hammer a lost card, don't retry a short balance before payday, don't
     * cluster attempts on weekends, and give up inside a bounded window.
     *
     * Everything here is a DEFAULT. An operator tunes a category at runtime from the console
     * (Settings → Dunning strategy); those overrides live in the `dunning_strategies` table and
     * win over these values. A category absent from both inherits the base curve above.
     */
    'dunning' => [

        'strategies' => [

            /*
             * Never schedule a retry beyond this many days after the first failure — the
             * bounded recovery window. An attempt whose (heuristic-adjusted) instant would fall
             * past it is dropped and the schedule exhausts.
             */
            'max_window_days' => (int) env('CBOX_BILLING_DUNNING_MAX_WINDOW_DAYS', 30),

            /*
             * The days-of-month insufficient-funds attempts are pulled forward to (typical
             * payday anchors) — retrying a short balance the moment it declines just declines
             * again, so the attempt is nudged to the next payday at or after its raw offset.
             *
             * @var list<int>
             */
            'payday_days' => [1, 15],

            /*
             * ISO-8601 weekday numbers (1=Mon … 7=Sun) an attempt is pushed OFF when a
             * category enables weekend avoidance — banks post fewer transactions on these days.
             *
             * @var list<int>
             */
            'quiet_weekdays' => [6, 7],

            /*
             * The per-decline-category overrides of the base curve. A category names only the
             * knobs it changes; anything omitted inherits (`backoff_days` → the base
             * `payment.retry.schedule`; `retry` → true; the heuristics → off). `hard` is forced
             * non-retryable in code regardless of what is set here.
             *
             *  - `retry`           — whether the category is retried at all.
             *  - `backoff_days`    — this category's own curve (day-offsets from first failure).
             *  - `avoid_weekends`  — push an attempt off the `quiet_weekdays`.
             *  - `align_to_payday` — pull an attempt forward to the next `payday_days` anchor.
             */
            'categories' => [

                // Lost/stolen/closed/expired/fraud — retrying the same method cannot succeed.
                'hard' => [
                    'retry' => false,
                ],

                // A short balance — spread wider, pull to payday, skip weekends.
                'insufficient_funds' => [
                    'backoff_days' => [2, 5, 9, 14],
                    'avoid_weekends' => true,
                    'align_to_payday' => true,
                ],

                // The issuer asked us to back off — a longer curve, skip weekends.
                'try_again_later' => [
                    'backoff_days' => [2, 5, 10, 16, 24],
                    'avoid_weekends' => true,
                ],

                // SCA — the customer authenticates; a short curve while the link is live.
                'needs_action' => [
                    'backoff_days' => [1, 3, 5],
                ],

                // `recoverable` and `unknown` are intentionally omitted: they inherit the base
                // curve, so the untuned path is identical to the legacy fixed schedule.
            ],
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
     * Transactional email — the brandable, localized lifecycle-email system. Templates ship
     * as defaults in code (resources/mail-templates/{locale}.php) and are overridden per
     * (event, locale, seller) in the `mail_templates` table; per-seller branding rides on the
     * selling entity (its brand colour, logo, from-identity, footer). These are the app-level
     * fallbacks used when a selling entity has not authored its own branding.
     *
     *  - `locales`         — the supported email locales (drop a new file in to add one).
     *  - `fallback_locale` — the last-resort locale; a resolution chain never dead-ends past it.
     *  - `branding`        — app defaults an entity's own values override.
     */
    'mail' => [
        'locales' => [
            'en' => 'English',
            'da' => 'Dansk',
        ],

        'fallback_locale' => env('CBOX_BILLING_MAIL_FALLBACK_LOCALE', 'en'),

        'branding' => [
            'product_name' => env('CBOX_BILLING_MAIL_PRODUCT_NAME', 'Cbox Billing'),
            'brand_color' => env('CBOX_BILLING_MAIL_BRAND_COLOR', '#2743b3'),
            'logo_url' => env('CBOX_BILLING_MAIL_LOGO_URL'),
            'from_name' => env('MAIL_FROM_NAME', 'Cbox Billing'),
            'from_email' => env('MAIL_FROM_ADDRESS', 'billing@example.com'),
            'reply_to' => env('CBOX_BILLING_MAIL_REPLY_TO'),
            'support_url' => env('CBOX_BILLING_MAIL_SUPPORT_URL'),
            'support_email' => env('CBOX_BILLING_MAIL_SUPPORT_EMAIL'),
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
     * Embeddable pricing tables + paywall (#57). The public, branded storefront a marketing
     * site drops in (`/pricing/{key}`, `/pricing/{key}/embed`, `/pricing/{key}.js`) and the
     * hosted paywall (`/paywall`). These surfaces are self-contained and served under no auth.
     */
    'storefront' => [
        /*
         * The default CTA hand-off target for a pricing table that sets no `cta_url_template`:
         * the operator's checkout / signup entry point, which receives the chosen
         * plan+currency+interval (as `{plan}`/`{currency}`/`{interval}`/`{price}` placeholders,
         * or an appended query string) and mints the real, org-scoped hosted checkout session.
         * Defaults to the app root so a CTA is always a valid link.
         */
        'checkout_url' => env('CBOX_BILLING_STOREFRONT_CHECKOUT_URL'),

        /*
         * The canonical, publicly reachable origin the embed snippets point back at (the host
         * that serves `/pricing/{key}`). Defaults to `APP_URL`; override when the marketing
         * embed must load the table from a different public hostname than the app runs on.
         */
        'embed_base_url' => env('CBOX_BILLING_STOREFRONT_EMBED_BASE_URL'),

        /*
         * The extra hosts a public-paywall `return_url` may point back at, beyond the app's own
         * origin and the storefront origins above. The paywall is served under no auth, so its
         * "maybe later" CTA is allow-listed to the seller's known/branding hosts (deny-by-default)
         * to close the open-redirect: an off-domain `return_url` is refused. Comma-separated bare
         * hosts (e.g. `app.acme.com,acme.com`); the seller branding's own support/logo hosts are
         * always allowed in addition to these.
         *
         * @var list<string>
         */
        'return_url_allowed_hosts' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('CBOX_BILLING_STOREFRONT_RETURN_URL_HOSTS', ''))),
            static fn (string $host): bool => $host !== '',
        )),
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

    /*
     * Optional usage/overage alerts. When an org's metered usage crosses one of these percent
     * thresholds of its included allowance, a branded, localized alert is queued (honouring the
     * customer's optional-notification opt-out), idempotent per (org, meter, period, threshold).
     */
    'usage_alerts' => [
        'enabled' => env('CBOX_USAGE_ALERTS_ENABLED', true),
        // Included-allowance percentages that trigger an alert (1–100). Only the highest newly
        // crossed threshold emails in any one sweep.
        'thresholds' => [80, 100],
    ],

    /*
     * Outbound webhook / event-bus delivery. The app fans its billing domain events out to
     * integrator-registered endpoints as signed HTTP POSTs. (Folded here from the former
     * config/cbox-billing.php so billing has one config root; env var names are unchanged.)
     */
    'webhooks' => [
        /*
         * SSRF enforcement for outbound endpoint URLs. Keep TRUE in any multi-tenant / hosted
         * deployment — it refuses endpoints that resolve to private/reserved/loopback/link-local
         * or cloud-metadata addresses at registration AND pins the resolved IP immediately before
         * each delivery (TOCTOU-closed, no redirects). A single-tenant on-prem operator who must
         * deliver to an internal host can set CBOX_WEBHOOKS_VERIFY_URL=false (also disabled in the
         * delivery unit tests so they can target a fake local URL).
         */
        'verify_url' => env('CBOX_WEBHOOKS_VERIFY_URL', true),

        /*
         * Retry budget. A failed delivery backs off exponentially (2^attempt minutes, capped at
         * the ceiling) up to `max_attempts`, then dead-letters so a gone endpoint stops consuming
         * retry cycles forever.
         */
        'max_attempts' => (int) env('CBOX_WEBHOOKS_MAX_ATTEMPTS', 8),
        'retry_ceiling_minutes' => (int) env('CBOX_WEBHOOKS_RETRY_CEILING_MINUTES', 360),

        /*
         * Per-attempt HTTP timeouts (seconds). Short by design: an outbound delivery must never
         * hold a worker for long, and a slow receiver is treated as a failure to retry.
         */
        'connect_timeout' => (int) env('CBOX_WEBHOOKS_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CBOX_WEBHOOKS_TIMEOUT', 10),

        /*
         * Replay-tolerance window (seconds) a receiver should accept when verifying the
         * `X-Cbox-Timestamp`. Documented for integrators; the signer stamps `time()`.
         */
        'tolerance_seconds' => (int) env('CBOX_WEBHOOKS_TOLERANCE_SECONDS', 300),

        /*
         * Drive the retry sweep from the scheduler. Opt out to call `webhooks:retry-pending`
         * yourself.
         */
        'schedule_retries' => env('CBOX_WEBHOOKS_SCHEDULE_RETRIES', true),

        /*
         * The queue the delivery jobs are pushed onto, so an operator can isolate webhook I/O
         * from the billing lifecycle workers.
         */
        'queue' => env('CBOX_WEBHOOKS_QUEUE', 'webhooks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | US economic nexus
    |--------------------------------------------------------------------------
    |
    | App-level inputs the cboxdk/laravel-nexus engine cannot infer. Thresholds come
    | from the us-tax-data dataset and cumulative sales from this app's invoices; these
    | are the seller-asserted facts.
    |
    | Physical presence — states the seller has an office, employees, or inventory/FBA
    | in, a trigger independent of sales — is operator-declared per state with an optional
    | effective window in the `seller_physical_presence` register (managed in the console),
    | NOT here. States the seller is otherwise registered in flow automatically from
    | `seller_tax_registrations` and report as Registered.
    |
    | `sole_sales_channel` declares whether this platform is the seller's ONLY US sales
    | channel. When false (the default), the report reflects ONLY sales invoiced here —
    | sales through other channels (marketplaces, other systems) also count toward each
    | state's threshold but are not visible — so the caveat is surfaced and a state shown
    | Below/Approaching may in fact already be Triggered once all channels are combined.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Tax pricing convention
    |--------------------------------------------------------------------------
    |
    | Whether the seller's catalog prices are quoted tax-EXCLUSIVE (net; tax added on top —
    | the US default) or tax-INCLUSIVE (gross; tax extracted from within — common for EU B2C).
    | The tax engine prices ONE mode per quote/invoice, so this is applied uniformly to a
    | document. An unknown value falls back to exclusive (deny-by-default).
    |
    */

    'tax' => [
        'pricing' => env('CBOX_TAX_PRICING', 'exclusive'),
    ],

    'nexus' => [
        'sole_sales_channel' => env('CBOX_NEXUS_SOLE_SALES_CHANNEL', false),

        // Economic-nexus alerts: the scheduled `nexus:alerts` sweep records each state that
        // crosses into Approaching/Triggered (once per measurement period) and emails the
        // recipients below. With no recipients, the crossing is still recorded for the console
        // watchlist — no email is sent and no mailbox is invented.
        'alerts' => [
            'enabled' => env('CBOX_NEXUS_ALERTS_ENABLED', true),
            'recipients' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('CBOX_NEXUS_ALERT_RECIPIENTS', '')),
            ))),
        ],
    ],

];
