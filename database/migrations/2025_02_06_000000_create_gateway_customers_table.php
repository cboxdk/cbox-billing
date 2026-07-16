<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gateway customer mapping (ADR-0009 Path B). A gateway (Stripe, …) vaults saved
 * cards and off-session methods against ITS OWN customer handle (`cus_…`), not the raw
 * cbox-id organization id. This table is the durable seam between the two: one row per
 * `(organization_id, gateway)` holding the gateway's customer id, created once on the
 * org's first intent for that gateway and reused for every intent and stored-method
 * operation thereafter.
 *
 * The `(organization_id, gateway)` pair is unique so a race between two first-intents for
 * one org collapses to a single mapping rather than two divergent gateway customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->string('gateway', 32);
            $table->string('gateway_customer_id');
            $table->timestamps();

            $table->unique(['organization_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_customers');
    }
};
