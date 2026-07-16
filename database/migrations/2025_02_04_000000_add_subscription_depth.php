<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription-management depth (ADR-0012): the durable state behind pause/resume,
 * deferred (change-at-period-end) plan changes, and attached add-ons.
 *
 *  - `paused_at` marks a subscription paused — access and metering are suspended (the
 *    meter-policy resolver stops resolving for it) and it does not renew until resumed;
 *    the engine models only active/canceled, so pause is an app-layer standing.
 *  - `pending_plan_id` / `pending_effective_at` carry a scheduled plan change that takes
 *    effect at the period end, surfaced distinctly from an immediate change and enacted
 *    when it comes due.
 *  - `subscription_add_ons` holds each attached add-on: an extra recurring charge with an
 *    optional per-cycle credit allotment, billed **aligned** to the base period or on its
 *    own **independent** cycle (anchor day/month + interval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable()->after('cancel_at_period_end');
            $table->foreignId('pending_plan_id')->nullable()->after('paused_at')->constrained('plans')->nullOnDelete();
            $table->timestamp('pending_effective_at')->nullable()->after('pending_plan_id');
        });

        Schema::create('subscription_add_ons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('key');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->string('alignment')->default('aligned');
            $table->unsignedBigInteger('credit_allotment')->default(0);
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->unsignedTinyInteger('anchor_month')->nullable();
            $table->string('interval')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_add_ons');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn(['paused_at', 'pending_effective_at']);
        });
    }
};
