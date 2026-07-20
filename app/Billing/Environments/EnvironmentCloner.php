<?php

declare(strict_types=1);

namespace App\Billing\Environments;

use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Billing\Mode\EnvironmentScope;
use App\Models\Coupon;
use App\Models\DunningStrategy;
use App\Models\Environment;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Feature;
use App\Models\MailTemplate;
use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use App\Models\PricingTable;
use App\Models\PricingTableFeature;
use App\Models\PricingTablePlan;
use App\Models\Product;
use App\Models\SellerEntity;
use App\Models\SellerTaxRegistration;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deep-copies one billing {@see Environment}'s CONFIG into a brand-new sandbox plane.
 *
 * WHAT IS COPIED (the config surface, all scoped by `environment`): the catalog (products →
 * plans → per-currency prices → tiers, plus entitlements, credit grants and feature grants),
 * the metered dimensions (meters) and boolean features, the seller register + branding +
 * per-jurisdiction tax registrations, the transactional-mail template overrides, the storefront
 * (pricing tables → plan columns → feature rows), A/B experiments + their variants (config side),
 * per-category dunning strategies, and coupons. Every intra-config RELATIONSHIP is preserved by
 * remapping foreign keys through per-table id maps as rows are replicated — so a cloned plan
 * still points at its cloned product/prices, a cloned pricing table at its cloned columns, a
 * cloned seller at its cloned tax registrations, and so on. Self-references (a plan's default
 * successor, an experiment's promoted variant) are rewired in a second pass once every row exists.
 *
 * WHAT IS NOT COPIED: all transactional/tenant data — subscriptions, invoices, customers,
 * credit notes, the ledger, wallet, dunning STATE, redemptions, licenses, webhook deliveries,
 * experiment impressions/conversions. The clone starts with an EMPTY BOOK: just config.
 *
 * GATEWAY SECRETS: per-environment gateway credentials DO live in the DB now
 * (`environment_gateways`, encrypted at rest), but that table is DELIBERATELY EXCLUDED from the
 * config-copy surface — the cloner replicates only the catalog / branding / templates / storefront
 * / experiments-config / dunning-strategies / coupons models, never gateway credentials. The clone
 * is created with `gateway_key_mode = test`, so it routes through the fake gateway until the
 * operator sets its OWN test keys — no source secret can ever leak into a clone.
 *
 * DENY-BY-DEFAULT: refuses to target the reserved production key, an invalid key, or an
 * existing environment (a re-clone is refused, never a silent overwrite). The whole copy runs in
 * one transaction, so a failure leaves no half-cloned plane.
 *
 * SELLER IDS: `seller_entities` keeps a globally-unique string PRIMARY key, so a cloned seller
 * is stored under a plane-namespaced id (`{newKey}__{sourceId}`) and every reference to it is
 * remapped — two planes therefore never collide on the string id while the seller's identity and
 * branding are fully copied.
 */
class EnvironmentCloner implements ClonesEnvironments
{
    public function clone(Environment $source, string $newKey, ?string $name = null): Environment
    {
        $this->guard($newKey);

        return DB::transaction(function () use ($source, $newKey, $name): Environment {
            $target = Environment::query()->create([
                'key' => $newKey,
                'name' => $name ?? ucfirst($newKey),
                'type' => EnvironmentType::Sandbox,
                'protected' => false,
                'gateway_key_mode' => GatewayKeyMode::Test,
            ]);

            $this->copyConfig($source->key, $target);

            return $target;
        });
    }

    /**
     * Deep-copy `$source`'s config into an EXISTING `$target` plane (the reseed seam). The target
     * must already have had its config wiped — this only replicates rows — and no transactional
     * data is copied. Runs in its own transaction so a failure leaves no half-seeded config.
     */
    public function copyConfigInto(Environment $source, Environment $target): void
    {
        DB::transaction(function () use ($source, $target): void {
            $this->copyConfig($source->key, $target);
        });
    }

    /** Refuse a reserved, malformed, or already-taken target key before any write happens. */
    private function guard(string $newKey): void
    {
        if ($newKey === Environment::PRODUCTION) {
            throw EnvironmentCloneException::reservedKey($newKey);
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $newKey) !== 1) {
            throw EnvironmentCloneException::invalidKey($newKey);
        }

        if (Environment::query()->where('key', $newKey)->exists()) {
            throw EnvironmentCloneException::keyTaken($newKey);
        }
    }

    /** Replicate the whole config surface from `$sourceKey` into `$target`, remapping relationships. */
    private function copyConfig(string $sourceKey, Environment $target): void
    {
        $targetKey = $target->key;

        // --- Catalog roots (no config FKs between them). -------------------------------------
        $meters = $this->replicateInto($this->sourceRows(Meter::class, $sourceKey), $targetKey, static function (): void {});
        $features = $this->replicateInto($this->sourceRows(Feature::class, $sourceKey), $targetKey, static function (): void {});
        $products = $this->replicateInto($this->sourceRows(Product::class, $sourceKey), $targetKey, static function (): void {});

        // --- Plans (product FK; self-referential successor rewired in a second pass). ---------
        $sourcePlans = $this->sourceRows(Plan::class, $sourceKey);
        $plans = $this->replicateInto($sourcePlans, $targetKey, function (Model $plan) use ($products): void {
            $this->remapFk($plan, 'product_id', $products);
            $plan->setAttribute('default_successor_plan_id', null);
        });
        $this->rewireSelfReference($sourcePlans, $plans, 'default_successor_plan_id', Plan::class);

        // --- Plan children. ------------------------------------------------------------------
        $prices = $this->replicateInto($this->sourceRows(PlanPrice::class, $sourceKey), $targetKey, function (Model $row) use ($plans): void {
            $this->remapFk($row, 'plan_id', $plans);
        });
        $this->replicateInto($this->sourceRows(PlanPriceTier::class, $sourceKey), $targetKey, function (Model $row) use ($prices): void {
            $this->remapFk($row, 'plan_price_id', $prices);
        });
        $this->replicateInto($this->sourceRows(PlanEntitlement::class, $sourceKey), $targetKey, function (Model $row) use ($plans, $meters): void {
            $this->remapFk($row, 'plan_id', $plans);
            $this->remapFk($row, 'meter_id', $meters);
        });
        $this->replicateInto($this->sourceRows(PlanCreditGrant::class, $sourceKey), $targetKey, function (Model $row) use ($plans): void {
            $this->remapFk($row, 'plan_id', $plans);
        });
        $this->replicateInto($this->sourceRows(PlanFeature::class, $sourceKey), $targetKey, function (Model $row) use ($plans, $features): void {
            $this->remapFk($row, 'plan_id', $plans);
            $this->remapFk($row, 'feature_id', $features);
        });

        // --- Seller register + branding + tax registrations + mail templates. ----------------
        $sellers = $this->cloneSellers($sourceKey, $targetKey);
        $this->replicateInto($this->sourceRows(SellerTaxRegistration::class, $sourceKey), $targetKey, function (Model $row) use ($sellers): void {
            $this->remapSellerFk($row, 'seller_entity_id', $sellers);
        });
        $this->replicateInto($this->sourceRows(MailTemplate::class, $sourceKey), $targetKey, function (Model $row) use ($sellers): void {
            $this->remapSellerFk($row, 'seller_entity_id', $sellers);
        });

        // --- Storefront (pricing tables → columns → feature rows). ---------------------------
        $pricingTables = $this->replicateInto($this->sourceRows(PricingTable::class, $sourceKey), $targetKey, function (Model $row) use ($sellers): void {
            $this->remapSellerFk($row, 'seller_entity_id', $sellers);
        });
        $this->replicateInto($this->sourceRows(PricingTablePlan::class, $sourceKey), $targetKey, function (Model $row) use ($pricingTables, $plans): void {
            $this->remapFk($row, 'pricing_table_id', $pricingTables);
            $this->remapFk($row, 'plan_id', $plans);
            $this->remapFk($row, 'annual_plan_id', $plans);
        });
        $this->replicateInto($this->sourceRows(PricingTableFeature::class, $sourceKey), $targetKey, function (Model $row) use ($pricingTables, $features): void {
            $this->remapFk($row, 'pricing_table_id', $pricingTables);
            $this->remapFk($row, 'feature_id', $features);
        });

        // --- Dunning strategies (no config FKs). ---------------------------------------------
        $this->replicateInto($this->sourceRows(DunningStrategy::class, $sourceKey), $targetKey, static function (): void {});

        // --- Experiments + variants (config side; promoted-variant self-ref second pass). -----
        $sourceExperiments = $this->sourceRows(Experiment::class, $sourceKey);
        $experiments = $this->replicateInto($sourceExperiments, $targetKey, function (Model $row) use ($pricingTables): void {
            $this->remapFk($row, 'pricing_table_id', $pricingTables);
            $row->setAttribute('promoted_variant_id', null);
        });
        $variants = $this->replicateInto($this->sourceRows(ExperimentVariant::class, $sourceKey), $targetKey, function (Model $row) use ($experiments, $pricingTables): void {
            $this->remapFk($row, 'experiment_id', $experiments);
            $this->remapFk($row, 'served_pricing_table_id', $pricingTables);
        });
        $this->rewireSelfReference($sourceExperiments, $experiments, 'promoted_variant_id', Experiment::class, $variants);

        // --- Coupons (catalog-ish config; the plane's livemode mirror + counters reset). ------
        $this->replicateInto($this->sourceRows(Coupon::class, $sourceKey), $targetKey, static function (Model $row) use ($target): void {
            $row->setAttribute('livemode', $target->livemode());
            $row->setAttribute('times_redeemed', 0);
        });
    }

    /**
     * All rows of `$model` in the source plane. The global {@see EnvironmentScope} is lifted and
     * the plane filtered explicitly on the BASE query builder (the same seam the scope uses — a
     * qualified column is a query filter, not a model attribute), so the read is independent of
     * the ambient (operator) plane and includes archived config rows (so a cloned child never
     * orphans on an archived parent).
     *
     * @param  class-string<Model>  $model
     * @return EloquentCollection<int, Model>
     */
    private function sourceRows(string $model, string $sourceKey): EloquentCollection
    {
        $query = $model::query()->withoutGlobalScope(EnvironmentScope::class);
        $query->getQuery()->where('environment', $sourceKey);

        return $query->get();
    }

    /**
     * Replicate each source row into `$targetKey`, applying `$mutate` (FK remaps / plane fixes)
     * before save, and return the source-id → clone-id map so children can rewire their FKs.
     *
     * @param  EloquentCollection<int, Model>  $rows
     * @param  callable(Model): void  $mutate
     * @return array<int, int>
     */
    private function replicateInto(EloquentCollection $rows, string $targetKey, callable $mutate): array
    {
        $map = [];

        foreach ($rows as $row) {
            $copy = $row->replicate();
            $copy->setAttribute('environment', $targetKey);
            $mutate($copy);
            $copy->save();

            $sourceId = $row->getKey();
            $cloneId = $copy->getKey();

            if (is_int($sourceId) && is_int($cloneId)) {
                $map[$sourceId] = $cloneId;
            }
        }

        return $map;
    }

    /**
     * Clone the seller register. `seller_entities` has a globally-unique string PRIMARY key, so
     * each clone is stored under a plane-namespaced id and the old → new id map is returned for
     * every referencing table (tax registrations, mail templates, pricing tables).
     *
     * @return array<string, string>
     */
    private function cloneSellers(string $sourceKey, string $targetKey): array
    {
        $map = [];

        foreach ($this->sourceRows(SellerEntity::class, $sourceKey) as $seller) {
            $sourceId = $seller->getKey();

            if (! is_string($sourceId)) {
                continue;
            }

            $cloneId = $targetKey.'__'.$sourceId;

            $copy = $seller->replicate();
            $copy->setAttribute('id', $cloneId);
            $copy->setAttribute('environment', $targetKey);
            $copy->save();

            $map[$sourceId] = $cloneId;
        }

        return $map;
    }

    /**
     * Rewire a self-referential FK (a plan's default successor, an experiment's promoted variant)
     * once every clone row exists. `$targetMap` defaults to the same-table id map, but a
     * cross-table pointer (experiment → variant) can pass its own map. The update runs on the
     * base query builder so the FK column is a plain query filter, not a checked model attribute.
     *
     * @param  EloquentCollection<int, Model>  $sourceRows
     * @param  array<int, int>  $selfMap  source-id → clone-id for the rows being updated
     * @param  class-string<Model>  $model
     * @param  array<int, int>|null  $targetMap  source-id → clone-id for the pointer's target (defaults to $selfMap)
     */
    private function rewireSelfReference(EloquentCollection $sourceRows, array $selfMap, string $attribute, string $model, ?array $targetMap = null): void
    {
        $targetMap ??= $selfMap;

        foreach ($sourceRows as $row) {
            $sourceId = $row->getKey();
            $pointer = $row->getAttribute($attribute);

            if (is_int($sourceId) && is_int($pointer) && isset($selfMap[$sourceId], $targetMap[$pointer])) {
                $model::query()
                    ->withoutGlobalScope(EnvironmentScope::class)
                    ->whereKey($selfMap[$sourceId])
                    ->toBase()
                    ->update([$attribute => $targetMap[$pointer]]);
            }
        }
    }

    /**
     * Remap an integer FK attribute through an id-map (null / absent / unmapped passes through
     * unchanged — e.g. a nullable pointer whose target was not in the source plane).
     *
     * @param  array<int, int>  $map
     */
    private function remapFk(Model $model, string $attribute, array $map): void
    {
        $current = $model->getAttribute($attribute);

        if (is_int($current) && isset($map[$current])) {
            $model->setAttribute($attribute, $map[$current]);
        }
    }

    /**
     * Remap a string seller FK through the seller id-map (null / unmapped passes through).
     *
     * @param  array<string, string>  $map
     */
    private function remapSellerFk(Model $model, string $attribute, array $map): void
    {
        $current = $model->getAttribute($attribute);

        if (is_string($current) && isset($map[$current])) {
            $model->setAttribute($attribute, $map[$current]);
        }
    }
}
