<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit grant's DISTRIBUTION and EXPIRY knobs (ADR-0013/0014) — the two catalog
 * degrees of freedom the scheduled renewal expands a recurring grant with:
 *
 *  - `amount_mode` — whether the authored `amount` is granted whole at each cadence
 *    boundary (`fixed`, the default and prior behaviour) or is a period TOTAL distributed
 *    evenly across the cadence slices the period holds (`distributed`), so a plan can
 *    spread an annual allotment across twelve monthly drips.
 *  - `rollover_seconds` — when set, each granted lot lives this many seconds from when it
 *    was granted (a `Duration` expiry) and unused credit ROLLS OVER and accumulates across
 *    periods; when null a recurring grant resets each period (`EndOfPeriod`, use-it-or-lose
 *    -it) — the reset-vs-rollover switch expressed purely as lot-attributed expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_credit_grants', function (Blueprint $table): void {
            $table->string('amount_mode')->default('fixed')->after('amount');
            $table->unsignedBigInteger('rollover_seconds')->nullable()->after('amount_mode');
        });
    }

    public function down(): void
    {
        Schema::table('plan_credit_grants', function (Blueprint $table): void {
            $table->dropColumn(['amount_mode', 'rollover_seconds']);
        });
    }
};
