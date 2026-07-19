<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhook / event-bus registry. Additive — no existing table is touched.
 *
 *  - `webhook_endpoints`: integrator-registered subscribers. The signing secret is stored
 *    encrypted at rest (Laravel `encrypted` cast over APP_KEY); `event_types` is the JSON set of
 *    catalog types this endpoint is subscribed to.
 *  - `webhook_deliveries`: one row per (endpoint, business event). `event_id` is the stable
 *    idempotency key — a re-emit of the same domain event collapses onto the same row, and the
 *    row id is the `delivery_id` a receiver dedupes on across retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('url');
            $table->text('secret'); // encrypted at rest via the model cast
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->json('event_types');
            $table->string('created_by_sub')->nullable();
            $table->timestamps();

            $table->index('active');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event_type')->index();
            $table->string('event_id'); // idempotency key for the source business event
            $table->json('payload');
            $table->unsignedInteger('attempt')->default(0);
            $table->string('status')->default('pending');
            $table->unsignedInteger('response_code')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // One delivery per endpoint per business event — the app's idempotency guard so a
            // re-emitted domain event never double-delivers.
            $table->unique(['endpoint_id', 'event_id']);

            // The retry sweep looks up due failures; the log view lists an endpoint's recent
            // deliveries newest-first.
            $table->index(['status', 'next_retry_at']);
            $table->index(['endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
