<?php

declare(strict_types=1);

use App\Billing\Payments\AdaptiveRetryStrategy;
use App\Billing\Payments\Dunning\DeclineCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-decline-category strategy OVERRIDES for adaptive dunning. One row per
 * {@see DeclineCategory}; a category with no row inherits the
 * shipped config defaults (config/billing.php → dunning.strategies). This is the durable
 * backing for the console strategy editor — an operator tunes a category's curve/heuristics
 * here without a redeploy, and the {@see AdaptiveRetryStrategy} reads the
 * effective (config ⊕ override) plan. Absent table / absent row ⇒ pure config, so the feature
 * is fully functional before an operator ever edits anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_strategies', function (Blueprint $table): void {
            $table->id();
            $table->string('category')->unique();
            // Whether the category is retried at all (a Hard category is forced false in code).
            $table->boolean('retry')->default(true);
            // Day-offsets from the first failure, one per attempt (JSON list<int>).
            $table->json('backoff_days');
            // Null ⇒ the ceiling is count(backoff_days).
            $table->unsignedInteger('max_attempts')->nullable();
            $table->boolean('avoid_weekends')->default(false);
            $table->boolean('align_to_payday')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_strategies');
    }
};
