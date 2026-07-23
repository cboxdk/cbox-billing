<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a selling entity has a PHYSICAL nexus (an office, employees, inventory/FBA) —
 * a nexus trigger independent of sales, operator-declared per state with an optional
 * effective window (a presence a seller opened, then later closed). Console-authored,
 * the same way tax registrations are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_physical_presence', function (Blueprint $table): void {
            $table->id();
            $table->string('environment')->index();
            $table->string('seller_entity_id');
            $table->string('subdivision'); // ISO 3166-2, e.g. "US-CA"
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->foreign('seller_entity_id')->references('id')->on('seller_entities')->cascadeOnDelete();
            $table->index('seller_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_physical_presence');
    }
};
