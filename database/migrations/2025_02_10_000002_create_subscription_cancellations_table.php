<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The captured record of a retention event — every cancellation (immediate, scheduled
 * for period end, or a pause-instead-of-cancel) and every win-back reactivation, with
 * the customer-supplied reason. Kept as an append-only log (not a column on the
 * subscription) so churn reasons survive a resubscribe and are directly queryable for
 * retention analytics: why customers leave, and which offers win them back.
 *
 * `mode`: `immediate` · `period_end` · `pause` · `reactivate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('organization_id');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('mode');
            $table->string('reason')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'mode']);
            $table->index(['reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_cancellations');
    }
};
