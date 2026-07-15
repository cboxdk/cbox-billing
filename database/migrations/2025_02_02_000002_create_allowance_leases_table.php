<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central budget's outstanding-lease register — one aggregate row per
 * `(org, meter)`. This is the durable authority the enforcement `AllowanceLeaseSource`
 * leases a slice of: a lease increments `outstanding`, a give-back decrements it, and
 * remaining includable allowance is `policy.allowance − outstanding`. Pessimistic by
 * design, so the sum of outstanding leases can never exceed the allowance — the node
 * that holds the slice can only ever over-reject, never over-grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('org');
            $table->string('meter');
            $table->unsignedBigInteger('outstanding')->default(0);
            $table->timestamps();

            $table->unique(['org', 'meter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_leases');
    }
};
