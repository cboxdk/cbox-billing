<?php

declare(strict_types=1);

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use App\Models\Environment;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise the binary test/live `livemode` plane into first-class, named ENVIRONMENTS. Every
 * plane-scoped table gains an `environment` string column — the stable key of an
 * {@see Environment} — backfilled from `livemode` (true → 'production', false →
 * 'sandbox'), indexed, and made the SOURCE OF TRUTH for scoping and key uniqueness. The legacy
 * `livemode` column is RETAINED as a synced mirror (written alongside `environment` by
 * {@see BelongsToEnvironment} and the operational stores) so external
 * data contracts (warehouse-export partitioning, the exported `livemode` columns, the DSAR
 * manifests) and the tamper-evident audit-chain hash stay byte-stable; a later wave drops it.
 *
 * Three shapes of change, mirroring the original `livemode` sweeps:
 *
 *  A. COLUMN + INDEX — the tenant/operational rows whose plane is a plain filter: add
 *     `environment` (default 'production'), backfill, index. Composite `(x, livemode)` read
 *     indexes gain a parallel `(x, environment)` index.
 *  B. UNIQUE-INDEX SWAP — the tables whose uniqueness includes the plane
 *     (`allowance_leases`, `gateway_customers`, `organization_feature_overrides`,
 *     `import_source_refs`, `usage_alert_dispatches`): the unique key moves from `…livemode`
 *     to `…environment`, so two sandboxes no longer collide (which `livemode` alone can't tell
 *     apart) — the whole reason the key must key on the environment.
 *  C. COMPOSITE-PRIMARY REBUILD — the dedup/per-account tables keyed `(key, livemode)`
 *     (`settled_payments`, `webhook_processed_events`, `account_standings`, `dunning_states`,
 *     `license_revocations`): SQLite cannot alter a primary key in place, so each is rebuilt
 *     PORTABLY (rename → create with the `(key, environment)` primary + retained `livemode`
 *     mirror → copy, computing `environment` from `livemode` → drop legacy).
 *
 * DEPLOY NOTE: additive columns/indexes for groups A & B; a table REBUILD (not a bare column
 * add) for the five group-C tables — small operational/dedup tables, but flag the rebuild in the
 * deploy plan, same as the original `livemode` operational migration.
 */
return new class extends Migration
{
    /**
     * Group A: plane is a plain filter — add `environment`, index it, backfill.
     * `test_clocks` defaults to 'sandbox' (a clock is always a sandbox object).
     *
     * @var list<string>
     */
    private array $columnTables = [
        'organizations', 'subscriptions', 'invoices', 'credit_notes', 'coupons',
        'coupon_redemptions', 'seat_assignments', 'wallet_adjustments', 'payment_retries',
        'webhook_endpoints', 'webhook_deliveries', 'issued_licenses',
        'billing_sessions', 'quotes', 'quote_lines', 'quote_acceptances',
        'tax_exemption_certificates', 'refunds', 'operator_audit_events',
        'import_run_entries', 'warehouse_sinks',
    ];

    public function up(): void
    {
        // A. plain column + index, backfilled from livemode.
        foreach ($this->columnTables as $table) {
            $this->addEnvironmentColumn($table);
        }

        // A. test_clocks — always sandbox: default the column to sandbox.
        $this->addEnvironmentColumn('test_clocks', default: 'sandbox');

        // A. composite read-index tables: add the parallel (x, environment) index too.
        $this->addEnvironmentColumn('import_runs');
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->index(['source', 'environment']);
        });

        $this->addEnvironmentColumn('approval_requests');
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->index(['status', 'environment']);
        });

        // B. unique-index swaps — move uniqueness from …livemode to …environment.
        $this->addEnvironmentColumn('allowance_leases', index: false);
        Schema::table('allowance_leases', function (Blueprint $table): void {
            $table->dropUnique(['org', 'meter', 'livemode']);
            $table->unique(['org', 'meter', 'environment']);
        });

        $this->addEnvironmentColumn('gateway_customers', index: false);
        Schema::table('gateway_customers', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'gateway', 'livemode']);
            $table->unique(['organization_id', 'gateway', 'environment']);
        });

        $this->addEnvironmentColumn('organization_feature_overrides', index: false);
        Schema::table('organization_feature_overrides', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'feature_id', 'livemode']);
            $table->unique(['organization_id', 'feature_id', 'environment']);
        });

        $this->addEnvironmentColumn('import_source_refs', index: false);
        Schema::table('import_source_refs', function (Blueprint $table): void {
            $table->dropUnique('import_source_refs_natural_key');
            $table->unique(['source', 'source_type', 'source_id', 'environment'], 'import_source_refs_env_key');
        });

        $this->addEnvironmentColumn('usage_alert_dispatches', index: false);
        Schema::table('usage_alert_dispatches', function (Blueprint $table): void {
            $table->dropUnique('usage_alert_once');
            $table->unique(['organization_id', 'meter_key', 'period_key', 'threshold', 'environment'], 'usage_alert_once_env');
        });

        // C. composite-primary rebuilds — the primary key moves to (key, environment).
        $this->rebuildSettledPayments();
        $this->rebuildProcessedEvents();
        $this->rebuildAccountStandings();
        $this->rebuildDunningStates();
        $this->rebuildLicenseRevocations();
    }

    public function down(): void
    {
        $this->restoreSettledPayments();
        $this->restoreProcessedEvents();
        $this->restoreAccountStandings();
        $this->restoreDunningStates();
        $this->restoreLicenseRevocations();

        Schema::table('usage_alert_dispatches', function (Blueprint $table): void {
            $table->dropUnique('usage_alert_once_env');
            $table->unique(['organization_id', 'meter_key', 'period_key', 'threshold', 'livemode'], 'usage_alert_once');
            $table->dropColumn('environment');
        });

        Schema::table('import_source_refs', function (Blueprint $table): void {
            $table->dropUnique('import_source_refs_env_key');
            $table->unique(['source', 'source_type', 'source_id', 'livemode'], 'import_source_refs_natural_key');
            $table->dropColumn('environment');
        });

        Schema::table('organization_feature_overrides', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'feature_id', 'environment']);
            $table->unique(['organization_id', 'feature_id', 'livemode']);
            $table->dropColumn('environment');
        });

        Schema::table('gateway_customers', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'gateway', 'environment']);
            $table->unique(['organization_id', 'gateway', 'livemode']);
            $table->dropColumn('environment');
        });

        Schema::table('allowance_leases', function (Blueprint $table): void {
            $table->dropUnique(['org', 'meter', 'environment']);
            $table->unique(['org', 'meter', 'livemode']);
            $table->dropColumn('environment');
        });

        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropIndex(['status', 'environment']);
        });

        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropIndex(['source', 'environment']);
        });

        foreach ([...$this->columnTables, 'test_clocks', 'import_runs', 'approval_requests'] as $table) {
            if (Schema::hasColumn($table, 'environment')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('environment');
                });
            }
        }
    }

    /**
     * Add + backfill the `environment` column on a plain group-A/B table. Defaults to
     * 'production' (mirroring `livemode`'s default true), then flips the sandbox rows.
     */
    private function addEnvironmentColumn(string $table, string $default = 'production', bool $index = true): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'environment')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($default, $index): void {
            $column = $blueprint->string('environment')->default($default);

            if ($index) {
                $column->index();
            }
        });

        // Backfill from the livemode mirror: false → sandbox (true rows already default production).
        DB::table($table)->where('livemode', false)->update(['environment' => 'sandbox']);
    }

    /** The SQL expression that derives the environment key from the legacy livemode column. */
    private function environmentFromLivemode(): Expression
    {
        return DB::raw("CASE WHEN livemode = 1 THEN 'production' ELSE 'sandbox' END");
    }

    // --- group C: up (composite-primary rebuilds, retaining the livemode mirror) --------------

    private function rebuildSettledPayments(): void
    {
        Schema::rename('settled_payments', 'settled_payments_legacy');

        Schema::create('settled_payments', function (Blueprint $table): void {
            $table->string('reference');
            $table->boolean('livemode')->default(true);
            $table->string('environment')->default('production')->index();
            $table->timestamp('settled_at')->useCurrent();
            $table->primary(['reference', 'environment']);
        });

        DB::table('settled_payments')->insertUsing(
            ['reference', 'livemode', 'settled_at', 'environment'],
            DB::table('settled_payments_legacy')->select(['reference', 'livemode', 'settled_at'])->addSelect($this->environmentFromLivemode()),
        );

        Schema::drop('settled_payments_legacy');
    }

    private function rebuildProcessedEvents(): void
    {
        Schema::rename('webhook_processed_events', 'webhook_processed_events_legacy');

        Schema::create('webhook_processed_events', function (Blueprint $table): void {
            $table->string('event_id');
            $table->boolean('livemode')->default(true);
            $table->string('environment')->default('production')->index();
            $table->timestamp('processed_at')->useCurrent();
            $table->primary(['event_id', 'environment']);
        });

        DB::table('webhook_processed_events')->insertUsing(
            ['event_id', 'livemode', 'processed_at', 'environment'],
            DB::table('webhook_processed_events_legacy')->select(['event_id', 'livemode', 'processed_at'])->addSelect($this->environmentFromLivemode()),
        );

        Schema::drop('webhook_processed_events_legacy');
    }

    private function rebuildAccountStandings(): void
    {
        Schema::rename('account_standings', 'account_standings_legacy');

        Schema::create('account_standings', function (Blueprint $table): void {
            $table->string('account');
            $table->boolean('livemode')->default(true);
            $table->string('environment')->default('production')->index();
            $table->string('state');
            $table->string('reason');
            $table->timestamps();
            $table->primary(['account', 'environment']);
        });

        DB::table('account_standings')->insertUsing(
            ['account', 'livemode', 'state', 'reason', 'created_at', 'updated_at', 'environment'],
            DB::table('account_standings_legacy')->select(['account', 'livemode', 'state', 'reason', 'created_at', 'updated_at'])->addSelect($this->environmentFromLivemode()),
        );

        Schema::drop('account_standings_legacy');
    }

    private function rebuildDunningStates(): void
    {
        Schema::rename('dunning_states', 'dunning_states_legacy');

        Schema::create('dunning_states', function (Blueprint $table): void {
            $table->string('account');
            $table->boolean('livemode')->default(true);
            $table->string('environment')->default('production')->index();
            $table->unsignedInteger('notices_sent')->default(0);
            $table->timestamp('last_notice_at')->nullable();
            $table->timestamps();
            $table->primary(['account', 'environment']);
        });

        DB::table('dunning_states')->insertUsing(
            ['account', 'livemode', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at', 'environment'],
            DB::table('dunning_states_legacy')->select(['account', 'livemode', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'])->addSelect($this->environmentFromLivemode()),
        );

        Schema::drop('dunning_states_legacy');
    }

    private function rebuildLicenseRevocations(): void
    {
        Schema::rename('license_revocations', 'license_revocations_legacy');

        Schema::create('license_revocations', function (Blueprint $table): void {
            $table->string('license_id');
            $table->boolean('livemode')->default(true);
            $table->string('environment')->default('production')->index();
            $table->timestamp('revoked_at');
            $table->string('reason')->nullable();
            $table->primary(['license_id', 'environment']);
        });

        DB::table('license_revocations')->insertUsing(
            ['license_id', 'livemode', 'revoked_at', 'reason', 'environment'],
            DB::table('license_revocations_legacy')->select(['license_id', 'livemode', 'revoked_at', 'reason'])->addSelect($this->environmentFromLivemode()),
        );

        Schema::drop('license_revocations_legacy');
    }

    // --- group C: down (restore the original (key, livemode) primaries) -----------------------

    private function restoreSettledPayments(): void
    {
        Schema::rename('settled_payments', 'settled_payments_new');

        Schema::create('settled_payments', function (Blueprint $table): void {
            $table->string('reference');
            $table->boolean('livemode')->default(true);
            $table->timestamp('settled_at')->useCurrent();
            $table->primary(['reference', 'livemode']);
        });

        DB::table('settled_payments')->insertUsing(
            ['reference', 'livemode', 'settled_at'],
            DB::table('settled_payments_new')->select('reference', 'livemode', 'settled_at'),
        );

        Schema::drop('settled_payments_new');
    }

    private function restoreProcessedEvents(): void
    {
        Schema::rename('webhook_processed_events', 'webhook_processed_events_new');

        Schema::create('webhook_processed_events', function (Blueprint $table): void {
            $table->string('event_id');
            $table->boolean('livemode')->default(true);
            $table->timestamp('processed_at')->useCurrent();
            $table->primary(['event_id', 'livemode']);
        });

        DB::table('webhook_processed_events')->insertUsing(
            ['event_id', 'livemode', 'processed_at'],
            DB::table('webhook_processed_events_new')->select('event_id', 'livemode', 'processed_at'),
        );

        Schema::drop('webhook_processed_events_new');
    }

    private function restoreAccountStandings(): void
    {
        Schema::rename('account_standings', 'account_standings_new');

        Schema::create('account_standings', function (Blueprint $table): void {
            $table->string('account');
            $table->boolean('livemode')->default(true);
            $table->string('state');
            $table->string('reason');
            $table->timestamps();
            $table->primary(['account', 'livemode']);
        });

        DB::table('account_standings')->insertUsing(
            ['account', 'livemode', 'state', 'reason', 'created_at', 'updated_at'],
            DB::table('account_standings_new')->select('account', 'livemode', 'state', 'reason', 'created_at', 'updated_at'),
        );

        Schema::drop('account_standings_new');
    }

    private function restoreDunningStates(): void
    {
        Schema::rename('dunning_states', 'dunning_states_new');

        Schema::create('dunning_states', function (Blueprint $table): void {
            $table->string('account');
            $table->boolean('livemode')->default(true);
            $table->unsignedInteger('notices_sent')->default(0);
            $table->timestamp('last_notice_at')->nullable();
            $table->timestamps();
            $table->primary(['account', 'livemode']);
        });

        DB::table('dunning_states')->insertUsing(
            ['account', 'livemode', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'],
            DB::table('dunning_states_new')->select('account', 'livemode', 'notices_sent', 'last_notice_at', 'created_at', 'updated_at'),
        );

        Schema::drop('dunning_states_new');
    }

    private function restoreLicenseRevocations(): void
    {
        Schema::rename('license_revocations', 'license_revocations_new');

        Schema::create('license_revocations', function (Blueprint $table): void {
            $table->string('license_id');
            $table->boolean('livemode')->default(true);
            $table->timestamp('revoked_at');
            $table->string('reason')->nullable();
            $table->primary(['license_id', 'livemode']);
        });

        DB::table('license_revocations')->insertUsing(
            ['license_id', 'livemode', 'revoked_at', 'reason'],
            DB::table('license_revocations_new')->select('license_id', 'livemode', 'revoked_at', 'reason'),
        );

        Schema::drop('license_revocations_new');
    }
};
