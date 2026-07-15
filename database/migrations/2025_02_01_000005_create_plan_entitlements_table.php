<?php

declare(strict_types=1);

use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's metered entitlement for one meter — the durable source the host resolves a
 * {@see MeterPolicy} from. `enabled` gates the
 * dimension first; `allowance` is the isolated included units; `multiplier` is the
 * per-overage-unit cost basis (nullable, no phantom default); `unlimited` zeroes cost
 * and cap explicitly; `overage` is the behaviour once the allowance is spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained('meters')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('allowance')->default(0);
            $table->double('multiplier')->nullable();
            $table->boolean('unlimited')->default(false);
            $table->string('overage')->default('block');
            $table->timestamps();

            $table->unique(['plan_id', 'meter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};
