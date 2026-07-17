<?php

declare(strict_types=1);

use Cbox\Billing\Reporting\MrrMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only MRR-movement log: one row every time a subscription's contributing
 * monthly-recurring amount actually changes (subscribe, trial conversion, plan/seat
 * change, cancel, reactivation). Each row records the amount BEFORE and AFTER the change
 * and the classified `kind` (new / expansion / contraction / churn / reactivation),
 * mirroring the engine {@see MrrMovement} decomposition — so the
 * analytics waterfall is read from real recorded events rather than reconstructed from
 * window edges (which can never see expansion/contraction).
 *
 * Idempotent per (subscription_id, occurred_at, kind): re-running a lifecycle pass
 * upserts the same row instead of double-recording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_mrr_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('organization_id');
            $table->string('currency', 3);
            $table->timestamp('occurred_at');
            $table->bigInteger('previous_mrr_minor');
            $table->bigInteger('new_mrr_minor');
            $table->string('kind');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['subscription_id', 'occurred_at', 'kind']);
            $table->index(['occurred_at', 'currency']);
            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_mrr_movements');
    }
};
