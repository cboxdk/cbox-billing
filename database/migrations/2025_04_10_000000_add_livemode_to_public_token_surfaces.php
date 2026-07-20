<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the test/live isolation hole on the PUBLIC token surfaces (HP1). The hosted
 * checkout/portal sessions, CPQ quotes (+ their lines and acceptances), and the gateway
 * customer vault mapping carried NO plane partition, so — because the public `web`/token
 * routes leave the ambient billing mode at its LIVE default — a TEST checkout/portal/quote
 * token resolved against LIVE-scoped data, and a TEST setup-intent wrote into the LIVE
 * `gateway_customers` mapping (whose unique key did not include the plane).
 *
 * Additive, backfill-safe: every existing row is stamped `livemode = true` (the live plane),
 * so nothing already built changes behaviour. The models mix in `BelongsToMode`, and the
 * public controllers resolve the token FIRST, read its `livemode`, and set the request's
 * plane from it before any org/plan/subscription/gateway query — the row is the source of
 * truth for the request's plane.
 *
 * `gateway_customers` additionally moves its uniqueness from `(organization_id, gateway)` to
 * `(organization_id, gateway, livemode)`, so a test vault mapping can coexist with the live
 * one for the same org+gateway instead of colliding with / overwriting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('organization_id');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('id');
        });

        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('quote_id');
        });

        Schema::table('quote_acceptances', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('quote_id');
        });

        Schema::table('gateway_customers', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('organization_id');
            // The plane is now part of the vault key: a test mapping never collides with the
            // live one for the same (organization, gateway).
            $table->dropUnique(['organization_id', 'gateway']);
            $table->unique(['organization_id', 'gateway', 'livemode']);
        });
    }

    public function down(): void
    {
        Schema::table('gateway_customers', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'gateway', 'livemode']);
            $table->unique(['organization_id', 'gateway']);
            $table->dropColumn('livemode');
        });

        Schema::table('quote_acceptances', function (Blueprint $table): void {
            $table->dropColumn('livemode');
        });

        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropColumn('livemode');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('livemode');
        });

        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->dropColumn('livemode');
        });
    }
};
