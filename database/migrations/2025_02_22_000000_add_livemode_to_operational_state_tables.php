<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complete the test/live plane partition on the per-org OPERATIONAL state tables the earlier
 * sweeps missed (re-review remediation). Each holds per-org operational state a test action must
 * not mix with live, but none is an Eloquent model with `BelongsToMode` — they are query-builder
 * stores — so the plane is enforced in the store (filter reads, stamp writes on `livemode`),
 * exactly as the durable `issued_licenses` store already is. Every existing row backfills to
 * `livemode = true` (the live plane), so nothing already built changes behaviour.
 *
 * Three shapes of change:
 *
 *  A. COLUMN-ONLY — `refunds` keeps its globally-unique `refund_id` primary (the engine mints a
 *     unique id per refund; it never collides across planes) and only GAINS `livemode`; the store
 *     scopes reads (idempotency lookup + the cumulative over-refund cap) to the current plane.
 *
 *  B. UNIQUE-INDEX SWAP — `allowance_leases` is keyed on `(org, meter)` (a surrogate id primary),
 *     where the ORG id is SHARED across planes, so its unique index moves to `(org, meter, livemode)`.
 *
 *  C. COMPOSITE-PRIMARY REBUILD — the dedup / per-org tables whose SINGLE-COLUMN string key is the
 *     dedup key a test action could legitimately reuse: `settled_payments` (reference),
 *     `webhook_processed_events` (event id), `account_standings` (org id), `dunning_states` (org id)
 *     and `license_revocations` (license id). Their key must become composite `(key, livemode)` so a
 *     test claim/standing/revocation coexists with the live one instead of colliding on the primary
 *     key. SQLite cannot alter a primary key in place, so these are rebuilt PORTABLY (rename →
 *     create with the composite primary → copy, defaulting livemode to true → drop the legacy table).
 *
 * DEPLOY NOTE: additive column for group A and an index swap for group B; a table REBUILD (not a
 * bare column add) for the five group-C tables — small operational/dedup tables, but flag the
 * rebuild in the deploy plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A. Column-only (globally-unique surrogate key — no cross-plane collision possible).
        Schema::table('refunds', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('refund_id')->index();
        });

        // B. Shared-org key: the plane joins the lease uniqueness so a test lease never collides
        // with (or draws on) the live allowance for the same (org, meter).
        Schema::table('allowance_leases', function (Blueprint $table): void {
            $table->boolean('livemode')->default(true)->after('meter');
            $table->dropUnique(['org', 'meter']);
            $table->unique(['org', 'meter', 'livemode']);
        });

        // C. Rebuild the five single-column-key tables with a composite (key, livemode) primary.
        $this->rebuildSettledPayments();
        $this->rebuildProcessedEvents();
        $this->rebuildAccountStandings();
        $this->rebuildDunningStates();
        $this->rebuildLicenseRevocations();
    }

    public function down(): void
    {
        Schema::table('allowance_leases', function (Blueprint $table): void {
            $table->dropUnique(['org', 'meter', 'livemode']);
            $table->unique(['org', 'meter']);
            $table->dropColumn('livemode');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('livemode');
        });

        $this->restoreSettledPayments();
        $this->restoreProcessedEvents();
        $this->restoreAccountStandings();
        $this->restoreDunningStates();
        $this->restoreLicenseRevocations();
    }

    // --- group C: up (composite-primary rebuilds) --------------------------------------------

    private function rebuildSettledPayments(): void
    {
        Schema::rename('settled_payments', 'settled_payments_legacy');

        Schema::create('settled_payments', function (Blueprint $table): void {
            $table->string('reference');
            $table->boolean('livemode')->default(true);
            $table->timestamp('settled_at')->useCurrent();
            $table->primary(['reference', 'livemode']);
        });

        DB::table('settled_payments')->insertUsing(
            ['reference', 'settled_at'],
            DB::table('settled_payments_legacy')->select('reference', 'settled_at'),
        );

        Schema::drop('settled_payments_legacy');
    }

    private function rebuildProcessedEvents(): void
    {
        Schema::rename('webhook_processed_events', 'webhook_processed_events_legacy');

        Schema::create('webhook_processed_events', function (Blueprint $table): void {
            $table->string('event_id');
            $table->boolean('livemode')->default(true);
            $table->timestamp('processed_at')->useCurrent();
            $table->primary(['event_id', 'livemode']);
        });

        DB::table('webhook_processed_events')->insertUsing(
            ['event_id', 'processed_at'],
            DB::table('webhook_processed_events_legacy')->select('event_id', 'processed_at'),
        );

        Schema::drop('webhook_processed_events_legacy');
    }

    private function rebuildAccountStandings(): void
    {
        Schema::rename('account_standings', 'account_standings_legacy');

        Schema::create('account_standings', function (Blueprint $table): void {
            $table->string('account');
            $table->boolean('livemode')->default(true);
            $table->string('state');
            $table->string('reason');
            $table->timestamps();
            $table->primary(['account', 'livemode']);
        });

        DB::table('account_standings')->insertUsing(
            ['account', 'state', 'reason', 'created_at', 'updated_at'],
            DB::table('account_standings_legacy')->select('account', 'state', 'reason', 'created_at', 'updated_at'),
        );

        Schema::drop('account_standings_legacy');
    }

    private function rebuildDunningStates(): void
    {
        Schema::rename('dunning_states', 'dunning_states_legacy');

        Schema::create('dunning_states', function (Blueprint $table): void {
            $table->string('account');
            $table->boolean('livemode')->default(true);
            $table->unsignedInteger('notices_sent')->default(0);
            $table->timestamp('last_notice_at')->nullable();
            $table->timestamps();
            $table->primary(['account', 'livemode']);
        });

        DB::table('dunning_states')->insertUsing(
            ['account', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'],
            DB::table('dunning_states_legacy')->select('account', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'),
        );

        Schema::drop('dunning_states_legacy');
    }

    private function rebuildLicenseRevocations(): void
    {
        Schema::rename('license_revocations', 'license_revocations_legacy');

        Schema::create('license_revocations', function (Blueprint $table): void {
            $table->string('license_id');
            $table->boolean('livemode')->default(true);
            $table->timestamp('revoked_at');
            $table->string('reason')->nullable();
            $table->primary(['license_id', 'livemode']);
        });

        DB::table('license_revocations')->insertUsing(
            ['license_id', 'revoked_at', 'reason'],
            DB::table('license_revocations_legacy')->select('license_id', 'revoked_at', 'reason'),
        );

        Schema::drop('license_revocations_legacy');
    }

    // --- group C: down (restore the original single-column primaries, live rows only) ---------

    private function restoreSettledPayments(): void
    {
        Schema::rename('settled_payments', 'settled_payments_new');

        Schema::create('settled_payments', function (Blueprint $table): void {
            $table->string('reference')->primary();
            $table->timestamp('settled_at')->useCurrent();
        });

        DB::table('settled_payments')->insertUsing(
            ['reference', 'settled_at'],
            DB::table('settled_payments_new')->where('livemode', true)->select('reference', 'settled_at'),
        );

        Schema::drop('settled_payments_new');
    }

    private function restoreProcessedEvents(): void
    {
        Schema::rename('webhook_processed_events', 'webhook_processed_events_new');

        Schema::create('webhook_processed_events', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->timestamp('processed_at')->useCurrent();
        });

        DB::table('webhook_processed_events')->insertUsing(
            ['event_id', 'processed_at'],
            DB::table('webhook_processed_events_new')->where('livemode', true)->select('event_id', 'processed_at'),
        );

        Schema::drop('webhook_processed_events_new');
    }

    private function restoreAccountStandings(): void
    {
        Schema::rename('account_standings', 'account_standings_new');

        Schema::create('account_standings', function (Blueprint $table): void {
            $table->string('account')->primary();
            $table->string('state');
            $table->string('reason');
            $table->timestamps();
        });

        DB::table('account_standings')->insertUsing(
            ['account', 'state', 'reason', 'created_at', 'updated_at'],
            DB::table('account_standings_new')->where('livemode', true)->select('account', 'state', 'reason', 'created_at', 'updated_at'),
        );

        Schema::drop('account_standings_new');
    }

    private function restoreDunningStates(): void
    {
        Schema::rename('dunning_states', 'dunning_states_new');

        Schema::create('dunning_states', function (Blueprint $table): void {
            $table->string('account')->primary();
            $table->unsignedInteger('notices_sent')->default(0);
            $table->timestamp('last_notice_at')->nullable();
            $table->timestamps();
        });

        DB::table('dunning_states')->insertUsing(
            ['account', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'],
            DB::table('dunning_states_new')->where('livemode', true)->select('account', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'),
        );

        Schema::drop('dunning_states_new');
    }

    private function restoreLicenseRevocations(): void
    {
        Schema::rename('license_revocations', 'license_revocations_new');

        Schema::create('license_revocations', function (Blueprint $table): void {
            $table->string('license_id')->primary();
            $table->timestamp('revoked_at');
            $table->string('reason')->nullable();
        });

        DB::table('license_revocations')->insertUsing(
            ['license_id', 'revoked_at', 'reason'],
            DB::table('license_revocations_new')->where('livemode', true)->select('license_id', 'revoked_at', 'reason'),
        );

        Schema::drop('license_revocations_new');
    }
};
