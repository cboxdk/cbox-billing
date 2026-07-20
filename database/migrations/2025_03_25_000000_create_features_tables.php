<?php

declare(strict_types=1);

use Cbox\License\Capabilities;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The boolean / non-metered feature-entitlements dimension — the product-gating sibling of the
 * metered allowance path (meters + plan entitlements). Three additive tables, none of which the
 * metered path reads:
 *
 *  1. `features` — the feature catalog. `key` is a stable slug (`sso`, `custom_domains`,
 *     `api_access`, `platform.multi_tenant`) drawn from the SAME vocabulary the on-prem license
 *     `entitlements` speak ({@see Capabilities}), so a hosted subscription and a
 *     self-hosted license gate on the same names. `type` is boolean (pure on/off) or config
 *     (carries a typed value/limit, e.g. `max_projects=10`); `value_type` types that value.
 *  2. `plan_features` — a plan grants a set of features (enabled + optional config value). The
 *     boolean/config peer of `plan_entitlements`, authored on the same plan detail hub.
 *  3. `organization_feature_overrides` — an org-level grant/revoke that wins over the plan
 *     resolution, so an operator can turn a feature on/off for one customer (audit-logged).
 *
 * A referenced feature is archived (`archived_at`), never hard-deleted, so a plan grant or an
 * org override never orphans — mirroring the meter/product archival rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            // 'boolean' (pure on/off) | 'config' (carries a typed value/limit).
            $table->string('type', 16)->default('boolean');
            // For a config feature: how its stored value is typed ('integer' | 'string').
            // Null for a boolean feature, which carries no value.
            $table->string('value_type', 16)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            // Whether the plan grants the feature. A disabled row is an explicit "not granted"
            // (deny-by-default already covers an absent row, so a stored disabled row is rare).
            $table->boolean('enabled')->default(true);
            // The config value the plan grants, stored as a string and typed on resolution by
            // the feature's `value_type`. Null for a boolean feature or an unset config value.
            $table->string('value', 255)->nullable();
            $table->timestamps();

            // At most one grant row per (plan, feature).
            $table->unique(['plan_id', 'feature_id']);
        });

        Schema::create('organization_feature_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            // true = grant the feature for this org even if the plan doesn't; false = revoke it
            // even if the plan does. Either way the override wins over the plan resolution.
            $table->boolean('granted');
            // The config value the override forces (config features only); null falls back to
            // the plan's value when granting, or is irrelevant when revoking.
            $table->string('value', 255)->nullable();
            // The operator-supplied reason, surfaced in the console + carried into the audit row.
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'feature_id']);
            $table->index('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_feature_overrides');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('features');
    }
};
