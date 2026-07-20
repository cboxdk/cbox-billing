<?php

declare(strict_types=1);

use App\Billing\Mode\LivemodeScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete the test/live plane partition on the two per-org tenant-state tables the earlier
 * sweeps missed (re-review remediation): `organization_feature_overrides` (an org-level
 * feature grant/revoke that wins over the plan resolution) and `tax_exemption_certificates`
 * (the captured proof that zero-rates an org's tax in a jurisdiction). Both are read/written
 * directly by org id off the public portal + the tax/features seams, so without a plane a TEST
 * request warmed / mutated LIVE feature state or read a LIVE exemption, and vice-versa.
 *
 * Additive and backfill-safe: every existing row is stamped `livemode = true` (the live plane),
 * so nothing already built changes behaviour. The models mix in `BelongsToMode`; the
 * {@see LivemodeScope} then confines every read to the request's plane.
 *
 * `organization_feature_overrides` additionally moves its uniqueness from
 * `(organization_id, feature_id)` to `(organization_id, feature_id, livemode)`, so a test
 * override can coexist with the live one for the same (org, feature) instead of colliding.
 * `tax_exemption_certificates` carries no such unique key (an org may hold several certificates
 * per jurisdiction over time), so it only gains the column + its plane index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_feature_overrides', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('organization_id');
            // The plane is now part of the override key: a test override never collides with the
            // live one for the same (organization, feature).
            $table->dropUnique(['organization_id', 'feature_id']);
            $table->unique(['organization_id', 'feature_id', 'livemode']);
        });

        Schema::table('tax_exemption_certificates', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('organization_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tax_exemption_certificates', function (Blueprint $table): void {
            $table->dropColumn('livemode');
        });

        Schema::table('organization_feature_overrides', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'feature_id', 'livemode']);
            $table->unique(['organization_id', 'feature_id']);
            $table->dropColumn('livemode');
        });
    }
};
