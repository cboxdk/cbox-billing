<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only attempts timeline for a smart-retry — one row per event in a failed charge's
 * recovery: the initial failure (attempt 0), each automated/manual retry, a card-updater
 * re-attempt, and the terminal recovered/exhausted outcome. It is the audit log the console
 * detail panel renders ("what was tried, when, why, and what the gateway said") and the source
 * for the average-attempts-to-recover analytic. Distinct from the `payment_retries` row, which
 * holds only the CURRENT state; this holds the HISTORY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_retry_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_retry_id')->constrained('payment_retries')->cascadeOnDelete();
            // 0 = the initial failure notice; 1..N = the fired retries.
            $table->unsignedInteger('attempt');
            // failed · recovered · exhausted · scheduled · authenticate · card_updated · stopped
            $table->string('outcome');
            $table->string('decline_code')->nullable();
            $table->string('decline_category')->nullable();
            $table->string('gateway_reference')->nullable();
            // Why this attempt happened / what comes next — a short human note for the timeline.
            $table->string('detail')->nullable();
            // When the NEXT attempt is scheduled after this event (null on a terminal event).
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['payment_retry_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_retry_attempts');
    }
};
