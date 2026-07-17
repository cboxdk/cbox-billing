<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two lifecycle-depth columns on the subscription row:
 *
 *  - `trial_ends_at` — when a subscription opened `Trialing` is due to convert to a
 *    paying `Active` (first charge). Null for a subscription that never trialed.
 *  - `canceled_at`   — when a subscription was actually canceled (immediate cancel, or a
 *    due end-of-period cancellation enacted at renewal). It bounds the win-back window a
 *    Canceled subscription may be reactivated within.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('trial_ends_at')->nullable()->after('cancel_at_period_end');
            $table->timestamp('canceled_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['trial_ends_at', 'canceled_at']);
        });
    }
};
