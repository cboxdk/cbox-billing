<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable smart-retry (dunning) state for a failed renewal charge. One row per
 * invoice under retry: how many attempts have fired, the ceiling, when the next attempt
 * is due, and the terminal outcome. The row is the idempotency key — an attempt slot
 * fires once because completing it advances `next_attempt_at` (or closes the row) in the
 * same transaction, so a re-dispatched or overlapping pass never double-charges an
 * attempt.
 *
 * `status`: `retrying` (attempts pending) · `recovered` (a retry settled → Active) ·
 * `exhausted` (the schedule ran out → the terminal action fired).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_retries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->string('organization_id');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');
            $table->string('status')->default('retrying');
            $table->timestamp('first_failed_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_reference')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_retries');
    }
};
