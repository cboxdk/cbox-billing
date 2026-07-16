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
 */
Schedule::command('billing:reconcile-active')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('billing:apply-scheduled-changes')->hourly()->withoutOverlapping();
Schedule::command('billing:invoice')->monthlyOn(1, '02:00')->withoutOverlapping();
Schedule::command('billing:dunning')->dailyAt('06:00')->withoutOverlapping();
