<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency ledger for the economic-nexus alert. One row per (seller entity, state,
 * measurement period, status) records that the operator was already alerted that the state
 * crossed into Approaching or Triggered — so a scheduled sweep surfaces each crossing exactly
 * ONCE per measurement period; the unique key is the concurrency-safe guard. A fresh period
 * (a new measurement year) re-alerts a still-exposed state. Environment-scoped so a sandbox
 * crossing and a live crossing are tracked independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexus_alert_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('environment')->index();
            $table->string('seller_entity_id');
            $table->string('subdivision');          // ISO 3166-2, e.g. "US-CA"
            $table->string('period_key', 16);        // the measurement period, e.g. the year "2026"
            $table->string('status', 16);            // approaching | triggered
            $table->timestamp('created_at')->nullable();

            $table->unique(['environment', 'seller_entity_id', 'subdivision', 'period_key', 'status'], 'nexus_alert_once');
            $table->index('seller_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexus_alert_dispatches');
    }
};
