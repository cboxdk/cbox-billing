<?php

declare(strict_types=1);

use App\Billing\Mode\LivemodeScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sandbox / test-mode plane partition (additive, default LIVE). Every tenant-state table an
 * integrator creates rows in gets a `livemode` boolean defaulting to TRUE, so existing rows
 * backfill to the live plane and current behaviour is unchanged. The {@see LivemodeScope}
 * keys off this column to keep a live credential and a test credential on strictly separate
 * datasets. Indexed because the scope adds a `where livemode = ?` to every read.
 */
return new class extends Migration
{
    /** @var list<string> The tenant-state tables that carry a plane. */
    private array $tables = [
        'organizations',
        'subscriptions',
        'invoices',
        'credit_notes',
        'coupons',
        'coupon_redemptions',
        'seat_assignments',
        'wallet_adjustments',
        'payment_retries',
        'webhook_endpoints',
        'webhook_deliveries',
        'issued_licenses',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'livemode')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('livemode')->default(true)->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'livemode')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('livemode');
            });
        }
    }
};
