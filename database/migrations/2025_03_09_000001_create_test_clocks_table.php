<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The test-clock table (the headline sandbox feature). A test clock holds a NAME and a
 * `now_at` virtual time; test subscriptions are bound to it via `subscriptions.test_clock_id`.
 * Advancing `now_at` runs the due billing logic for the bound subscriptions — renewals,
 * trial conversions, dunning — deterministically and idempotently, so an integrator can test
 * a year of renewals in seconds. `charge_outcome` lets a clock be set to decline its charges,
 * the deterministic knob for exercising the dunning flow. A clock is a test-only object, so
 * it always carries `livemode = false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_clocks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamp('now_at');
            $table->string('charge_outcome')->default('succeed');
            $table->boolean('livemode')->default(false)->index();
            $table->string('created_by_sub')->nullable();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('test_clock_id')->nullable()->after('id')
                ->constrained('test_clocks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('test_clock_id');
        });

        Schema::dropIfExists('test_clocks');
    }
};
