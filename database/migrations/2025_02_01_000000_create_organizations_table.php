<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A billing organization. Its primary key is the cbox-id organization identifier —
 * the same `org` handle the metering, reconciliation, and standing surfaces are keyed
 * on across the engine — so the app never invents a second identity for a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('billing_email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
