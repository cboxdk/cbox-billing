<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the per-organization notification opt-out ledger to a billing ENVIRONMENT (plane). Until
 * now `notification_preferences` keyed only on (org, event), so a SANDBOX portal opt-out for an
 * org suppressed the SAME org's optional emails in PRODUCTION (and survived the sandbox teardown).
 *
 * Add the `environment` partition column (default 'production'; every existing row is a real
 * production preference, so the default backfills them correctly), index it, and move the
 * uniqueness from (org, event) to (org, event, environment) so the two planes hold independent
 * opt-out rows for the same org.
 *
 * DEPLOY NOTE: additive column + unique-index swap on `notification_preferences`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->string('environment')->default('production')->after('organization_id')->index();
        });

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'event_type']);
            $table->unique(['organization_id', 'event_type', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'event_type', 'environment']);
            $table->unique(['organization_id', 'event_type']);
            $table->dropColumn('environment');
        });
    }
};
