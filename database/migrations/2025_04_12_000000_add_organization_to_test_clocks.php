<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope a test clock to an organization (P3). The programmatic advance
 * (`POST /api/v1/test/clocks/{id}/advance`) only checked the token was test-mode, not that it
 * owned the clock — so an org-scoped test token could fast-forward ANY org's clock. Giving a
 * clock an optional `organization_id` lets the API assert the caller may act for it; a clock
 * with no org stays operator-only on the API.
 *
 * Additive + nullable (no backfill needed — test clocks are sandbox-only): existing clocks keep
 * a null org and remain reachable from the console (permission-gated) and to operator tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_clocks', function (Blueprint $table): void {
            $table->string('organization_id')->nullable()->after('name');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('test_clocks', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
