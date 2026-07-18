<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only record of plan-retirement handling (ADR-0016): the reminder sent ahead
 * of a cutoff, the migration enacted at a renewal, and the deny-by-default `unresolved`
 * flag surfaced to ops. Keyed by `(subscription_id, retires_at, type)` unique so every
 * handling is idempotent — the reminder is sent at most once per subscription per
 * retirement window, and a migration is recorded exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_retirement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('organization_id');
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            // The cutoff this handling belongs to — the retirement window key.
            $table->timestamp('retires_at');
            // reminder | migrated | unresolved
            $table->string('type');
            // The resolution enacted (resolved-to-successor / resolved-to-default /
            // resolved-to-cancel / unresolved-retirement), for the ops surface.
            $table->string('outcome')->nullable();
            $table->foreignId('successor_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('detail')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'retires_at', 'type']);
            $table->index(['type', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_retirement_events');
    }
};
