<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two durable guards behind exactly-once webhook ingest.
 *
 *  - `webhook_processed_events` — first-sight dedup on the gateway's own event id.
 *  - `settled_payments`         — the authoritative settle-once claim on the
 *    payment/invoice reference, a UNIQUE insert so two different events that both mean
 *    "invoice X paid" still settle X exactly once and survive a crash mid-apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_processed_events', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->timestamp('processed_at')->useCurrent();
        });

        Schema::create('settled_payments', function (Blueprint $table): void {
            $table->string('reference')->primary();
            $table->timestamp('settled_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_processed_events');
        Schema::dropIfExists('settled_payments');
    }
};
