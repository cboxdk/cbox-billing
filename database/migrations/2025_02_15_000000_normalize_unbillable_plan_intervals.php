<?php

declare(strict_types=1);

use Cbox\Billing\Subscription\Enums\BillingInterval;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The billing engine renews only month and year cadences
 * ({@see BillingInterval}). Plans were previously
 * authorable on `week` and `quarter` too, but those were never billed on their own
 * cadence — the renewal service silently fell them back to a monthly cycle, so a quarter
 * plan over-charged 3× and a week plan under-charged. Authoring them is now refused
 * (see the plan-authoring guard).
 *
 * Any plan already stored on an unbillable interval is normalized here to `month` — the
 * cadence it was in fact being billed on — so the stored interval is honest rather than a
 * silent lie. This is an explicit, one-way data repair; a genuine sub-monthly or
 * quarterly cadence needs an engine feature and cannot be reconstructed from the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')
            ->whereIn('interval', ['week', 'quarter'])
            ->update(['interval' => 'month']);
    }

    public function down(): void
    {
        // One-way repair: the original week/quarter intent cannot be recovered, and the
        // engine could not bill it anyway, so there is nothing safe to restore.
    }
};
