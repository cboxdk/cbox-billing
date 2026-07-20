<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion;

use App\Billing\Environments\EnvironmentCloner;
use App\Billing\Environments\Promotion\Descriptors\ChildDescriptor;
use App\Billing\Environments\Promotion\Descriptors\DependencyDescriptor;
use App\Billing\Environments\Promotion\Descriptors\ObjectDescriptor;
use App\Billing\Environments\Promotion\Descriptors\SelfReferenceDescriptor;
use App\Models\Coupon;
use App\Models\DunningStrategy;
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

/**
 * The declarative registry of every promotable config object type — the single source of truth
 * the {@see ConfigPromotion} engine reads to match objects by natural key, diff them, detect
 * blocking dependency conflicts, and remap relationships to the target plane's ids. It mirrors
 * the clone surface of {@see EnvironmentCloner} so the two stay in step.
 *
 * The descriptor ORDER is the dependency order the engine upserts in: a type only ever depends on
 * a type declared before it (meters/features/products before plans; sellers before mail templates
 * and pricing tables; pricing tables before experiments), so by the time an object is written its
 * dependencies' target ids are already resolved.
 */
class ConfigSurface
{
    /** @var array<string, ObjectDescriptor>|null */
    private ?array $byType = null;

    /**
     * Every top-level object descriptor, in dependency (upsert) order.
     *
     * @return list<ObjectDescriptor>
     */
    public function objects(): array
    {
        return [
            // --- Catalog roots (no config dependencies between them). ---
            new ObjectDescriptor(
                type: 'meter',
                group: PromotionGroup::Catalog,
                modelClass: Meter::class,
                naturalKeyAttributes: ['key'],
                compareFields: ['name', 'unit', 'aggregation', 'display', 'archived_at'],
            ),
            new ObjectDescriptor(
                type: 'feature',
                group: PromotionGroup::Catalog,
                modelClass: Feature::class,
                naturalKeyAttributes: ['key'],
                compareFields: ['name', 'description', 'type', 'value_type', 'archived_at'],
            ),
            new ObjectDescriptor(
                type: 'product',
                group: PromotionGroup::Catalog,
                modelClass: Product::class,
                naturalKeyAttributes: ['key'],
                compareFields: ['name', 'description', 'archived_at'],
            ),

            // --- Selling entities + branding + their tax registrations (before mail/pricing). ---
            new ObjectDescriptor(
                type: 'seller',
                group: PromotionGroup::Branding,
                modelClass: SellerEntity::class,
                naturalKeyAttributes: ['id'],
                compareFields: [
                    'legal_name', 'registration_number', 'establishment', 'currency', 'invoice_prefix',
                    'is_default', 'archived_at', 'brand_color', 'logo_url', 'from_name', 'from_email',
                    'reply_to', 'footer_address', 'support_url', 'support_email', 'default_locale',
                ],
                children: [
                    new ChildDescriptor(
                        relation: 'taxRegistrations',
                        type: 'tax-registration',
                        modelClass: SellerTaxRegistration::class,
                        parentKey: 'seller_entity_id',
                        naturalKeyAttributes: ['country', 'scheme'],
                        compareFields: ['number', 'subdivision'],
                    ),
                ],
                mintsStringId: true,
            ),

            // --- Plans (+ prices/tiers, entitlements, credit grants, feature grants). ---
            new ObjectDescriptor(
                type: 'plan',
                group: PromotionGroup::Catalog,
                modelClass: Plan::class,
                naturalKeyAttributes: ['key'],
                compareFields: ['name', 'interval', 'active', 'retires_at'],
                dependencies: [new DependencyDescriptor('product_id', 'product')],
                selfReferences: [new SelfReferenceDescriptor('default_successor_plan_id', type: 'plan')],
                children: [
                    new ChildDescriptor(
                        relation: 'prices',
                        type: 'plan-price',
                        modelClass: PlanPrice::class,
                        parentKey: 'plan_id',
                        naturalKeyAttributes: ['currency'],
                        compareFields: ['price_minor', 'pricing_model', 'package_size'],
                        children: [
                            new ChildDescriptor(
                                relation: 'tiers',
                                type: 'price-tier',
                                modelClass: PlanPriceTier::class,
                                parentKey: 'plan_price_id',
                                naturalKeyAttributes: ['sort_order'],
                                compareFields: ['up_to', 'unit_minor', 'flat_minor'],
                            ),
                        ],
                    ),
                    new ChildDescriptor(
                        relation: 'entitlements',
                        type: 'plan-entitlement',
                        modelClass: PlanEntitlement::class,
                        parentKey: 'plan_id',
                        naturalKeyAttributes: [],
                        compareFields: ['enabled', 'allowance', 'multiplier', 'unlimited', 'overage'],
                        dependencies: [new DependencyDescriptor('meter_id', 'meter')],
                        naturalKeyDependencies: ['meter_id'],
                    ),
                    new ChildDescriptor(
                        relation: 'creditGrants',
                        type: 'plan-credit-grant',
                        modelClass: PlanCreditGrant::class,
                        parentKey: 'plan_id',
                        naturalKeyAttributes: ['pool', 'denomination'],
                        compareFields: ['kind', 'cadence', 'amount', 'amount_mode', 'rollover_seconds'],
                    ),
                    new ChildDescriptor(
                        relation: 'featureGrants',
                        type: 'plan-feature',
                        modelClass: PlanFeature::class,
                        parentKey: 'plan_id',
                        naturalKeyAttributes: [],
                        compareFields: ['enabled', 'value'],
                        dependencies: [new DependencyDescriptor('feature_id', 'feature')],
                        naturalKeyDependencies: ['feature_id'],
                    ),
                ],
            ),

            // --- Coupons + dunning strategies (no config dependencies). ---
            new ObjectDescriptor(
                type: 'coupon',
                group: PromotionGroup::Coupons,
                modelClass: Coupon::class,
                naturalKeyAttributes: ['code'],
                compareFields: [
                    'name', 'discount_type', 'percent_off', 'amount_off_minor', 'currency', 'duration',
                    'duration_in_periods', 'max_redemptions', 'max_redemptions_per_customer', 'redeem_by',
                    'applies_to', 'applies_to_plans', 'active', 'archived_at',
                ],
            ),
            new ObjectDescriptor(
                type: 'dunning-strategy',
                group: PromotionGroup::Dunning,
                modelClass: DunningStrategy::class,
                naturalKeyAttributes: ['category'],
                compareFields: ['retry', 'backoff_days', 'max_attempts', 'avoid_weekends', 'align_to_payday'],
            ),

            // --- Mail templates (depend on sellers). ---
            new ObjectDescriptor(
                type: 'mail-template',
                group: PromotionGroup::Mail,
                modelClass: MailTemplate::class,
                naturalKeyAttributes: ['event_type', 'locale', 'seller_entity_id'],
                compareFields: ['subject', 'body'],
                dependencies: [new DependencyDescriptor('seller_entity_id', 'seller')],
            ),

            // --- Storefront pricing tables (+ plan columns, feature rows; depend on sellers). ---
            new ObjectDescriptor(
                type: 'pricing-table',
                group: PromotionGroup::PricingTables,
                modelClass: PricingTable::class,
                naturalKeyAttributes: ['key'],
                compareFields: [
                    'name', 'currencies', 'default_currency', 'interval_toggle',
                    'cta_label', 'cta_url_template', 'active',
                ],
                dependencies: [new DependencyDescriptor('seller_entity_id', 'seller')],
                children: [
                    new ChildDescriptor(
                        relation: 'planColumns',
                        type: 'pricing-table-plan',
                        modelClass: PricingTablePlan::class,
                        parentKey: 'pricing_table_id',
                        naturalKeyAttributes: [],
                        compareFields: ['sort_order', 'featured', 'badge', 'highlight'],
                        dependencies: [
                            new DependencyDescriptor('plan_id', 'plan'),
                            new DependencyDescriptor('annual_plan_id', 'plan', required: false),
                        ],
                        naturalKeyDependencies: ['plan_id'],
                    ),
                    new ChildDescriptor(
                        relation: 'featureRows',
                        type: 'pricing-table-feature',
                        modelClass: PricingTableFeature::class,
                        parentKey: 'pricing_table_id',
                        naturalKeyAttributes: [],
                        compareFields: ['sort_order'],
                        dependencies: [new DependencyDescriptor('feature_id', 'feature')],
                        naturalKeyDependencies: ['feature_id'],
                    ),
                ],
            ),

            // --- Experiments (+ variants; depend on pricing tables). ---
            new ObjectDescriptor(
                type: 'experiment',
                group: PromotionGroup::Experiments,
                modelClass: Experiment::class,
                naturalKeyAttributes: ['key'],
                compareFields: ['name', 'hypothesis', 'status', 'primary_metric', 'started_at', 'concluded_at'],
                dependencies: [new DependencyDescriptor('pricing_table_id', 'pricing-table', required: false)],
                selfReferences: [new SelfReferenceDescriptor('promoted_variant_id', childRelation: 'variants')],
                children: [
                    new ChildDescriptor(
                        relation: 'variants',
                        type: 'experiment-variant',
                        modelClass: ExperimentVariant::class,
                        parentKey: 'experiment_id',
                        naturalKeyAttributes: ['label'],
                        compareFields: ['is_control', 'weight', 'sort_order'],
                        dependencies: [new DependencyDescriptor('served_pricing_table_id', 'pricing-table', required: false)],
                    ),
                ],
            ),
        ];
    }

    /**
     * The descriptor for a top-level type slug, or null when it names none.
     */
    public function forType(string $type): ?ObjectDescriptor
    {
        if ($this->byType === null) {
            $map = [];
            foreach ($this->objects() as $descriptor) {
                $map[$descriptor->type] = $descriptor;
            }
            $this->byType = $map;
        }

        return $this->byType[$type] ?? null;
    }

    /**
     * The top-level descriptors that belong to a group, in dependency order.
     *
     * @return list<ObjectDescriptor>
     */
    public function forGroup(PromotionGroup $group): array
    {
        return array_values(array_filter(
            $this->objects(),
            static fn (ObjectDescriptor $descriptor): bool => $descriptor->group === $group,
        ));
    }
}
