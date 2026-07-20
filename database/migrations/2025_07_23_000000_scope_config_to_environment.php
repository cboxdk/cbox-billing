<?php

declare(strict_types=1);

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the first-class {@see Environment} plane from the tenant/operational surface
 * (Env Wave 1) onto the full CONFIG surface. Catalog, branding, templates, storefront,
 * experiments, dunning strategies and coupons were treated as GLOBAL — one shared catalog for
 * every plane. The multi-environment sandbox needs each environment to hold its OWN config, so
 * every config table gains the `environment` string key (the stable {@see Environment::$key}),
 * backfilled to 'production' so the live plane is unchanged, and stamped/scoped by
 * {@see BelongsToEnvironment}. Because production is the default/active plane everywhere, the
 * existing single catalog keeps resolving exactly as before.
 *
 * Two shapes of change (NO table rebuilds this wave — every change is a plain column add or an
 * index swap, so SQLite handles them in place):
 *
 *  A. COLUMN + INDEX — config rows whose uniqueness is already keyed by a parent FK id (a
 *     child of a plan / pricing-table / seller / experiment, whose parent id is globally
 *     unique and therefore already per-environment) OR carry no natural key. Add `environment`
 *     (default 'production'), index it. The child's `(plan_id, …)`-style unique needs no change.
 *  B. NATURAL-KEY UNIQUE SWAP — the config tables whose OWN natural key must now be unique
 *     WITHIN an environment (so a sandbox and production can each own a `pro` plan / a `WELCOME`
 *     coupon / a `hard_decline` dunning strategy): the unique moves from `(key)` to
 *     `(key, environment)`. `coupons` already gained `environment` in Wave 1 (its plane is a
 *     plain filter there) — here only its `code` unique is widened to include the environment.
 *
 * NOT DB-BACKED, so nothing to scope here: payment-gateway config (environment variables, see
 * SettingsController), retention reasons/offers and tax overrides (contract/config-driven, no
 * table — only `seller_tax_registrations` exists and is scoped in group A). `seller_entities`
 * keeps its string PRIMARY key globally unique (no rebuild); the cloner remaps seller ids into
 * the target plane, so two planes never collide on the string id.
 *
 * DEPLOY NOTE: additive columns + index swaps only — no table REBUILDS. Uniqueness that
 * CHANGED (dropped a global unique, added a per-environment one), for the deploy plan:
 *   - meters(key)            → meters(key, environment)
 *   - products(key)          → products(key, environment)
 *   - plans(key)             → plans(key, environment)
 *   - features(key)          → features(key, environment)
 *   - pricing_tables(key)    → pricing_tables(key, environment)
 *   - experiments(key)       → experiments(key, environment)
 *   - dunning_strategies(category) → dunning_strategies(category, environment)
 *   - coupons(code)          → coupons(code, environment)
 *   - mail_templates(event_type, locale, seller_entity_id)
 *                            → (event_type, locale, seller_entity_id, environment)
 */
return new class extends Migration
{
    /**
     * Group A: plane is a plain filter (uniqueness is a parent FK id, already per-environment,
     * or there is no natural key) — add `environment`, index, backfill 'production'.
     *
     * @var list<string>
     */
    private array $columnTables = [
        // Catalog children (unique on a globally-unique parent id → already per-environment).
        'plan_prices', 'plan_price_tiers', 'plan_entitlements', 'plan_credit_grants', 'plan_features',
        // Storefront children.
        'pricing_table_plans', 'pricing_table_features',
        // Seller register + branding children.
        'seller_tax_registrations',
        // Experiment arms (config side; impressions/conversions are transactional, left alone).
        'experiment_variants',
    ];

    public function up(): void
    {
        // A. plain column + index, defaulted to production (the live plane is unchanged).
        foreach ($this->columnTables as $table) {
            $this->addEnvironmentColumn($table);
        }

        // A. seller_entities: string PRIMARY key stays globally unique (no rebuild) — just add
        // the plane column + index so its scope resolves the active environment's sellers.
        $this->addEnvironmentColumn('seller_entities');

        // B. natural-key unique swaps — the config table's own key becomes per-environment.
        $this->addEnvironmentColumn('meters', index: false);
        Schema::table('meters', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });

        $this->addEnvironmentColumn('products', index: false);
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });

        $this->addEnvironmentColumn('plans', index: false);
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });

        $this->addEnvironmentColumn('features', index: false);
        Schema::table('features', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });

        $this->addEnvironmentColumn('pricing_tables', index: false);
        Schema::table('pricing_tables', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });

        $this->addEnvironmentColumn('experiments', index: false);
        Schema::table('experiments', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });

        $this->addEnvironmentColumn('dunning_strategies', index: false);
        Schema::table('dunning_strategies', function (Blueprint $table): void {
            $table->dropUnique(['category']);
            $table->unique(['category', 'environment']);
        });

        $this->addEnvironmentColumn('mail_templates', index: false);
        Schema::table('mail_templates', function (Blueprint $table): void {
            $table->dropUnique('mail_templates_key_unique');
            $table->unique(['event_type', 'locale', 'seller_entity_id', 'environment'], 'mail_templates_key_unique');
        });

        // B. coupons already carries `environment` (Wave 1) — only widen the code unique.
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->unique(['code', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropUnique(['code', 'environment']);
            $table->unique(['code']);
        });

        Schema::table('mail_templates', function (Blueprint $table): void {
            $table->dropUnique('mail_templates_key_unique');
            $table->unique(['event_type', 'locale', 'seller_entity_id'], 'mail_templates_key_unique');
            $table->dropColumn('environment');
        });

        $this->dropUniqueSwap('dunning_strategies', ['category', 'environment'], ['category']);
        $this->dropUniqueSwap('experiments', ['key', 'environment'], ['key']);
        $this->dropUniqueSwap('pricing_tables', ['key', 'environment'], ['key']);
        $this->dropUniqueSwap('features', ['key', 'environment'], ['key']);
        $this->dropUniqueSwap('plans', ['key', 'environment'], ['key']);
        $this->dropUniqueSwap('products', ['key', 'environment'], ['key']);
        $this->dropUniqueSwap('meters', ['key', 'environment'], ['key']);

        foreach ([...$this->columnTables, 'seller_entities'] as $table) {
            $this->dropEnvironmentColumn($table);
        }
    }

    /**
     * Add + backfill the `environment` column on a config table. Defaults to 'production' so
     * the existing single (global) catalog becomes the production plane's catalog with no data
     * move — there are no sandbox config rows yet, so the default is the whole backfill.
     */
    private function addEnvironmentColumn(string $table, bool $index = true): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'environment')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $column = $blueprint->string('environment')->default(Environment::PRODUCTION);

            if ($index) {
                $column->index();
            }
        });
    }

    private function dropEnvironmentColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'environment')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('environment');
            });
        }
    }

    /**
     * Reverse a group-B unique swap and drop the `environment` column.
     *
     * @param  list<string>  $currentUnique  the per-environment unique to drop
     * @param  list<string>  $restoreUnique  the original global unique to restore
     */
    private function dropUniqueSwap(string $table, array $currentUnique, array $restoreUnique): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($currentUnique, $restoreUnique): void {
            $blueprint->dropUnique($currentUnique);
            $blueprint->unique($restoreUnique);
            $blueprint->dropColumn('environment');
        });
    }
};
