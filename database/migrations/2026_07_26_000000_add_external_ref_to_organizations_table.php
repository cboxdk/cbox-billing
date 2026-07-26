<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the organization's IDENTITY from the caller's LOOKUP KEY.
 *
 * `organizations.id` was the consumer's own tenant key, supplied verbatim on the upsert
 * endpoint. Two problems followed from that single column doing both jobs:
 *
 *  1. IT WAS GUESSABLE. Tenant keys are slugs — `acme`, `globex`. The unauthenticated
 *     `/paywall` endpoint takes an org id and renders whether that org already holds a
 *     capability, in that org's billing currency, with no throttle. Anyone who could guess a
 *     tenant slug could enumerate customers and read a per-feature entitlement map.
 *  2. IT WAS GLOBALLY UNIQUE ACROSS PLANES. A tenant provisioned as `acme` against a test key
 *     could never be provisioned in production under the same handle: the upsert probed inside
 *     the production plane, missed, inserted, and hit the primary key — surfacing as an
 *     unhandled 500 on an endpoint documented as a freely repeatable idempotent upsert.
 *
 * So the id becomes an opaque generated ULID (matching the identity layer, which already
 * issues them) and the caller's key moves to `external_ref`, unique PER ENVIRONMENT. Both
 * problems dissolve rather than trading off: an id cannot be guessed because it carries no
 * meaning, and the same tenant key is free to name a distinct org in each plane, which is
 * what a sandbox is for.
 *
 * Existing rows keep their id and gain it as their `external_ref`, so a lookup by the caller's
 * key keeps resolving. Only NEW orgs get ULIDs — rewriting the primary key of live rows would
 * mean rewriting every dependent foreign key, and the ids already issued are not a security
 * problem worth that risk. New orgs are unguessable; that is what closes the enumeration path
 * for everything provisioned from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('external_ref')->nullable()->after('id');
        });

        // Backfill: an existing org's caller-supplied id IS its external ref.
        DB::table('organizations')->whereNull('external_ref')->update([
            'external_ref' => DB::raw(Schema::getConnection()->getQueryGrammar()->wrap('id')),
        ]);

        Schema::table('organizations', function (Blueprint $table): void {
            // Unique per PLANE, not globally: the same tenant key naming a sandbox org and a
            // production org is the normal case, and forbidding it is the bug this fixes.
            $table->unique(['external_ref', 'environment'], 'organizations_external_ref_env');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique('organizations_external_ref_env');
            $table->dropColumn('external_ref');
        });
    }
};
