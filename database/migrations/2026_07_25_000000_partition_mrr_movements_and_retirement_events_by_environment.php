<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes two holes in the plane partition.
 *
 * `subscription_mrr_movements` (created 2025-02-13) and `plan_retirement_events` (created
 * 2025-02-14) both landed AFTER the tables enumerated in the original livemode→environment sweep
 * and were never added to its list. Neither carries an `environment` column, so `EnvironmentScope`
 * cannot reach them and neither model composes `BelongsToEnvironment`. The consequences are real
 * and were reproduced:
 *
 *  - The MRR-movement waterfall reads the movement log UNSCOPED but adds the steady book from a
 *    plane-SCOPED subscription query — so half the waterfall is partitioned and half is not. A
 *    production console with zero subscriptions reported expansion MRR generated entirely by a
 *    sandbox experiment. That is the headline revenue figure.
 *  - `PlanRetirementService::unresolved()` filters by nothing at all, so a sandbox retirement with
 *    no successor appears in the PRODUCTION dashboard worklist. Because `Subscription` IS scoped,
 *    the banner cannot resolve the row and falls back to printing a raw organization id — a
 *    phantom account alongside real customers.
 *
 * Both get the same treatment as the original group-A tables: a `production`-defaulted, indexed
 * column, backfilled from the owning row's plane rather than from a `livemode` mirror (neither
 * table ever had one). The backfill joins through the row that DOES know its plane —
 * `subscriptions`/`organizations` for a movement, `subscriptions` for a retirement event — so
 * existing sandbox history lands in the sandbox rather than being silently promoted to production.
 *
 * A movement whose subscription has since been hard-deleted falls back to its organization's
 * plane; one with neither stays `production`, which is the pre-migration behaviour for that row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addEnvironmentColumn('subscription_mrr_movements');
        $this->addEnvironmentColumn('plan_retirement_events');

        $this->backfillFromSubscription('subscription_mrr_movements');
        $this->backfillFromOrganization('subscription_mrr_movements');
        $this->backfillFromSubscription('plan_retirement_events');
    }

    public function down(): void
    {
        foreach (['subscription_mrr_movements', 'plan_retirement_events'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'environment')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('environment');
                });
            }
        }
    }

    /** Add the plane column, defaulted to production and indexed for the scope's equality filter. */
    private function addEnvironmentColumn(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'environment')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('environment')->default('production')->index();
        });
    }

    /**
     * Backfill from the owning subscription's plane. Correlated rather than joined so it runs on
     * SQLite and Postgres alike; both tables are small enough that this is not worth optimising.
     */
    private function backfillFromSubscription(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('subscriptions')) {
            return;
        }

        DB::table($table)
            ->whereNotNull('subscription_id')
            ->update([
                'environment' => DB::raw(
                    '(select coalesce(max(s.environment), \'production\') from subscriptions s where s.id = '.$table.'.subscription_id)'
                ),
            ]);
    }

    /** Fallback for movements whose subscription row is gone: use the organization's plane. */
    private function backfillFromOrganization(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('organizations')) {
            return;
        }

        DB::table($table)
            ->whereNull('subscription_id')
            ->update([
                'environment' => DB::raw(
                    '(select coalesce(max(o.environment), \'production\') from organizations o where o.id = '.$table.'.organization_id)'
                ),
            ]);
    }
};
