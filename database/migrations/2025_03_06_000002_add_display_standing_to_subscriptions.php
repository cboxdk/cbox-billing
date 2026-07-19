<?php

declare(strict_types=1);

use App\Billing\Support\SubscriptionStanding;
use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialize the console display standing (PERF-3). The Subscriptions list and its tab
 * counts are filtered/tallied by the DERIVED standing (paused / trialing / past_due /
 * non_renewing / canceled / active), which was computed in PHP over the whole table — so the
 * list loaded every row to slice one page, and the counts loaded the whole table AGAIN.
 *
 * Persisting the standing turns both into indexed DB queries: `WHERE display_standing = ?`
 * with real pagination, and a single `GROUP BY` for the counts. The column is maintained on
 * the writes that change it (subscription lifecycle, invoice status/due-date) and by a daily
 * refresh (an invoice merely crossing its due date), and always equals
 * {@see SubscriptionStanding::of()} by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('display_standing', 20)->nullable()->index()->after('paused_at');
        });

        // Backfill existing rows from the same derivation the maintainer uses, so an upgraded
        // deployment's list/counts are correct on the first request.
        Subscription::query()->with('organization.invoices')->get()
            ->each(static function (Subscription $subscription): void {
                SubscriptionStanding::refreshFor($subscription);
            });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['display_standing']);
            $table->dropColumn('display_standing');
        });
    }
};
