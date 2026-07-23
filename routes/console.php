<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The billing lifecycle cadence.
 *
 *  - Reconcile the hot-path usage into the durable ledger on a short drift-window cadence
 *    (ADR-0003) — the convergent delta post is cheap and idempotent, so running it often
 *    only tightens ledger freshness.
 *  - Run the monthly invoicing pass at the start of each month.
 *  - Chase delinquent accounts once a day (dunning gates access only; it never touches
 *    credits or the ledger).
 *  - Fire the scheduled cycle renewal daily: it grants each subscription's recurring
 *    per-cycle credit allotments as they vest, advances the period on its boundary, renews
 *    add-ons, and issues the renewal invoice. The granting is idempotent and time-keyed
 *    (ADR-0002/0013/0014), so a daily cadence drips finer-grained allotments and rolls a
 *    period over exactly once — running it more often would only tighten freshness.
 */
Schedule::command('billing:reconcile-active')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('billing:apply-scheduled-changes')->hourly()->withoutOverlapping();

/*
 * Recompute the materialized subscription display standings (PERF-3) before the daily passes,
 * so an account whose invoice has merely crossed its due date reads as past_due on the console
 * even though no write touched it. Event-driven maintenance keeps it fresh the rest of the day.
 */
Schedule::command('billing:refresh-standings')->dailyAt('02:15')->withoutOverlapping();

/*
 * Migrate subscriptions off retiring plans (ADR-0016) BEFORE the daily renewal, so a
 * subscriber whose renewal is due is moved onto their chosen successor / the default (or
 * flagged unresolved) and never renews on the retired plan. Also sends the plan-retiring
 * reminder ahead of each cutoff. Idempotent — recorded per subscription per window.
 */
Schedule::command('billing:migrate-retiring-plans')->dailyAt('02:45')->withoutOverlapping();

Schedule::command('billing:renew')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('billing:invoice')->monthlyOn(1, '02:00')->withoutOverlapping();
Schedule::command('billing:dunning')->dailyAt('06:00')->withoutOverlapping();

// Optional usage/overage alerts: sweep metered orgs a few times a day and email any newly
// crossed included-allowance threshold (idempotent per org/meter/period/threshold).
Schedule::command('billing:usage-alerts')->everySixHours()->withoutOverlapping();

/*
 * Smart-retry dunning for failed renewal charges: chase each PastDue invoice on its backoff
 * schedule. Run daily — each attempt is idempotent per (invoice, attempt) and only fires
 * when its next offset has come due, so a daily cadence enacts the [1,3,5,7]-day schedule
 * without ever double-charging.
 */
Schedule::command('billing:retry-payments')->dailyAt('06:30')->withoutOverlapping();

/*
 * Convert due free trials: after the renewal pass, take each Trialing subscription whose
 * trial end has passed to a paying Active (first charge), and send the trial-ending reminder
 * as a trial crosses into its lead window. Idempotent — a converted trial is never
 * re-selected.
 */
Schedule::command('billing:convert-trials')->dailyAt('04:00')->withoutOverlapping();

/*
 * (Re)issue on-prem licenses for active subscriptions on a licensable plan, after the
 * daily renewal so a rolled-over paid period is reflected in the license expiry. The run
 * is idempotent (one active license per deployment), so a deployment already covering the
 * current period is skipped and only a period roll-over triggers a reissue.
 */
Schedule::command('billing:issue-licenses')->dailyAt('03:30')->withoutOverlapping();

/*
 * Prune the request-idempotency store (SEC-2 / L1): drop expired completed records (which can
 * hold a captured 2xx body) and reap stale, never-completed claims (a poisoned key that would
 * otherwise block its own retries forever). Hourly, so a poisoned key is never stuck for long.
 */
Schedule::command('billing:prune-idempotency')->hourly()->withoutOverlapping();

/*
 * Stage incremental dataset partitions to every enabled warehouse sink (data export → warehouse
 * sync). Each dataset ships only the rows added/changed since its per-(sink, dataset) watermark
 * (a snapshot dataset full-refreshes), alongside the copy-paste load manifest an operator or a
 * scheduled loader runs. Idempotent and cursor-driven, so an hourly cadence keeps the warehouse
 * fresh without ever double-delivering a row; a deployment that wants tighter freshness can run
 * it more often, or a per-sink cron via the sink's own schedule.
 */
Schedule::command('warehouse:sync')->hourly()->withoutOverlapping();

/*
 * Pull the ECB euro reference rates (and any operator overrides) into the fx_rates store for
 * consolidated reporting. Daily on weekdays after the ECB publishes (~16:00 CET); the store keeps
 * serving the last good rates under the nearest-before as-of policy, so a missed pull (weekend,
 * holiday, transient outage) never fabricates a rate. Idempotent — a re-run upserts.
 */
Schedule::command('fx:refresh')->weekdays()->at('16:30')->withoutOverlapping();

/*
 * Lapse past-expiry tax exemption certificates: flip `pending`/`verified` certificates whose
 * expiry has passed to `expired` so the console shows them as lapsed. The tax seam already
 * refuses a past-expiry certificate at calculation time, so this only keeps the stored status
 * honest. Daily, idempotent (an already-expired certificate is not re-selected).
 */
Schedule::command('tax:expire-certificates')->dailyAt('02:30')->withoutOverlapping();

/*
 * Economic-nexus alert sweep: evaluate the default seller's US exposure and record/notify any
 * state newly crossed into Approaching or Triggered. Nexus turns over slowly (cumulative annual
 * sales), so a daily pass is ample; idempotent per (seller, state, period, status), so it
 * surfaces each crossing exactly once per measurement period.
 */
Schedule::command('nexus:alerts')->dailyAt('05:30')->withoutOverlapping();
