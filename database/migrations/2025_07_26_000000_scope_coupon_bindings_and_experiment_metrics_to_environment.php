<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the reset/teardown gaps by giving the last transactional tables their `environment`
 * partition, so a plane teardown removes them cleanly (a plain DELETE … WHERE environment = ?):
 *
 *  - `subscription_coupons` — a durable coupon binding has no environment and no cascade from its
 *    subscription, so a reset (which wipes subscriptions) left DEAD bindings behind. Backfill each
 *    row from its subscription's environment.
 *  - `experiment_impressions` / `experiment_conversions` — transactional A/B counters that a reset
 *    left stale (experiments themselves are CONFIG and survive a reset). Backfill each from its
 *    parent experiment's environment.
 *
 * All three are stamped from the ambient plane on create thereafter (BelongsToEnvironment on the
 * models). Backfill defaults to 'production'; the correlated updates then set the real plane from
 * the parent row.
 *
 * DEPLOY NOTE: additive `environment` column (+ index) on subscription_coupons,
 * experiment_impressions, experiment_conversions; correlated backfill from the parent row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumn('subscription_coupons');
        $this->addColumn('experiment_impressions');
        $this->addColumn('experiment_conversions');

        // Backfill each child from its parent's environment.
        DB::table('subscription_coupons')->update([
            'environment' => DB::raw('(SELECT s.environment FROM subscriptions s WHERE s.id = subscription_coupons.subscription_id)'),
        ]);

        foreach (['experiment_impressions', 'experiment_conversions'] as $table) {
            DB::table($table)->update([
                'environment' => DB::raw("(SELECT e.environment FROM experiments e WHERE e.id = {$table}.experiment_id)"),
            ]);
        }

        // A NULL parent (orphan) leaves the 'production' default in place — never NULL.
        foreach (['subscription_coupons', 'experiment_impressions', 'experiment_conversions'] as $table) {
            DB::table($table)->whereNull('environment')->update(['environment' => 'production']);
        }
    }

    public function down(): void
    {
        foreach (['subscription_coupons', 'experiment_impressions', 'experiment_conversions'] as $table) {
            if (Schema::hasColumn($table, 'environment')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('environment');
                });
            }
        }
    }

    private function addColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'environment')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('environment')->default('production')->index();
        });
    }
};
