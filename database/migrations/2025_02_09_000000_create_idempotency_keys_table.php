<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable idempotency records for the mutating management endpoints. A client that retries
 * a `POST /subscriptions` (or a plan change / quantity / add-on / license issue) with the
 * same `Idempotency-Key` gets the FIRST attempt's stored response replayed instead of a
 * second effect — mirroring the settlement path's exactly-once discipline.
 *
 * The record is scoped by the caller (`scope` = a hash of the bearer token) so two tenants
 * can reuse the same key string without colliding, and carries a `request_hash` so reusing
 * a key with a different payload is a detectable conflict rather than a silent replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key');
            $table->string('scope', 64);
            $table->string('method', 10);
            $table->string('path');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            // One record per (key, caller): the unique constraint is the concurrency guard —
            // a racing duplicate loses the insert and is served the in-flight/stored outcome.
            $table->unique(['idempotency_key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
