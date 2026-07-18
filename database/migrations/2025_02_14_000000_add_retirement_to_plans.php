<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\ValueObjects\PlanRetirement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's sunset (ADR-0016): the hard cutoff after which the plan is retired and its
 * existing subscribers must resolve off it at their next renewal. `retires_at` is the
 * cutoff instant (null = not being sunset); `default_successor_plan_id` is the plan a
 * subscriber who makes no choice falls to at that renewal — null means there is no
 * default, so an unresolved subscriber is flagged rather than silently charged.
 *
 * Both project into the engine's {@see PlanRetirement}
 * value object carried on the catalog product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->timestamp('retires_at')->nullable()->after('active');
            $table->foreignId('default_successor_plan_id')
                ->nullable()
                ->after('retires_at')
                ->constrained('plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_successor_plan_id');
            $table->dropColumn('retires_at');
        });
    }
};
