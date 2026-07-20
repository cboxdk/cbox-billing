<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency ledger for the optional usage/overage alert. One row per
 * (organization, meter, billing period, threshold) records that the alert already fired, so a
 * scheduled sweep that runs many times a day emails the crossing exactly ONCE per period — the
 * unique key is the concurrency-safe guard. Plane-scoped (`livemode`) so a test-mode crossing
 * and a live-mode crossing are tracked independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_alert_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->string('meter_key', 120);
            // The billing period the allowance belongs to (its start date), so a new period fires
            // a fresh alert as usage climbs again.
            $table->string('period_key', 32);
            $table->unsignedSmallInteger('threshold');
            $table->boolean('livemode')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'meter_key', 'period_key', 'threshold', 'livemode'], 'usage_alert_once');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_alert_dispatches');
    }
};
