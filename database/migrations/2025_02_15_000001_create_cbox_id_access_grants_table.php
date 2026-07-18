<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local ACCESS MIRROR: which Cbox ID subjects (and which of their roles) may act on
 * which billing org, kept fresh out-of-band by the provisioning webhooks. Cbox ID owns
 * identity + assignment; this is a read model the billing side maintains so seat and
 * membership facts are queryable without a token round-trip.
 *
 * One row per (org, subject, role). A bare membership (no role yet — e.g. a directory
 * provision) is stored with an empty-string `role` so the uniqueness holds across drivers
 * (SQLite treats NULLs as distinct in a UNIQUE index). `environment_key` records the plane
 * the grant belongs to when the event carries it (additive; null on single-environment
 * deployments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbox_id_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->string('subject');
            $table->string('role')->default('');
            $table->string('environment_key')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'subject', 'role']);
            $table->index('subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbox_id_access_grants');
    }
};
