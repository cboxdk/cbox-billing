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
 * Migrate subscriptions off retiring plans (ADR-0016) BEFORE the daily renewal, so a
 * subscriber whose renewal is due is moved onto their chosen successor / the default (or
 * flagged unresolved) and never renews on the retired plan. Also sends the plan-retiring
 * reminder ahead of each cutoff. Idempotent — recorded per subscription per window.
 */
Schedule::command('billing:migrate-retiring-plans')->dailyAt('02:45')->withoutOverlapping();

Schedule::command('billing:renew')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('billing:invoice')->monthlyOn(1, '02:00')->withoutOverlapping();
Schedule::command('billing:dunning')->dailyAt('06:00')->withoutOverlapping();

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
