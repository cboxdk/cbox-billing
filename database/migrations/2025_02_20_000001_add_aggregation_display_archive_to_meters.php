<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist a meter's billable-metric aggregation and an optional display label, and give
 * meters the same archive path as products.
 *
 *  - `aggregation` — how the meter's raw usage events collapse into ONE billable quantity
 *    (the engine {@see Aggregation}: count / sum / max /
 *    unique_count / latest / weighted_sum). Defaults to `sum` — the engine's historical
 *    {@see MeterPolicy} default — so every existing
 *    meter's policy is unchanged.
 *  - `display` — an optional human label shown in the console; falls back to `name`.
 *  - `archived_at` — a meter referenced by entitlements/usage is archived, never
 *    hard-deleted, so its historical policy keeps resolving (null = active).
 *
 * All additive and nullable/defaulted, so the config-then-persisted catalog is unbroken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table): void {
            $table->string('aggregation')->default('sum')->after('unit');
            $table->string('display')->nullable()->after('aggregation');
            $table->timestamp('archived_at')->nullable()->after('display');
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table): void {
            $table->dropColumn(['aggregation', 'display', 'archived_at']);
        });
    }
};
