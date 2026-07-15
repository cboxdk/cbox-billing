<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog of metered dimensions. `key` is the stable dimension handle the
 * metering enforcer resolves policy for (the same `meter` on a UsageEvent); `unit`
 * names what one counted unit is (requests, seats, GB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('unit');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meters');
    }
};
