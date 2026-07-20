<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion;

use App\Billing\Environments\EnvironmentCloner;

/**
 * A selectable band of the config surface a {@see ConfigPromotion}
 * can publish from one environment to another. The groups mirror the clone surface exactly (see
 * {@see EnvironmentCloner}) so an operator reasons about the same
 * objects whether cloning a whole plane or promoting a slice of it back. Selection is
 * deny-by-default: nothing is promoted unless its group (or the individual object) is chosen.
 *
 * Each group names the ordered list of TOP-LEVEL config object types it carries (children —
 * a plan's prices/tiers/entitlements, a seller's tax registrations, a pricing table's columns —
 * travel with their parent object and are never selected on their own).
 */
enum PromotionGroup: string
{
    case Catalog = 'catalog';
    case Branding = 'branding';
    case Mail = 'mail';
    case PricingTables = 'pricing-tables';
    case Coupons = 'coupons';
    case Dunning = 'dunning';
    case Experiments = 'experiments';

    /** A short human label for the console picker and the diff preview. */
    public function label(): string
    {
        return match ($this) {
            self::Catalog => 'Catalog',
            self::Branding => 'Branding & sellers',
            self::Mail => 'Mail templates',
            self::PricingTables => 'Pricing tables',
            self::Coupons => 'Coupons',
            self::Dunning => 'Dunning strategies',
            self::Experiments => 'Experiments',
        };
    }

    /** One-line description of what the group publishes, for the console. */
    public function description(): string
    {
        return match ($this) {
            self::Catalog => 'Products, plans (with their prices, tiers, entitlements, credit grants and feature grants), meters and features.',
            self::Branding => 'Selling entities, branding and their per-jurisdiction tax registrations.',
            self::Mail => 'Transactional-email template overrides (per event type, locale and seller).',
            self::PricingTables => 'Public pricing tables with their plan columns and feature rows.',
            self::Coupons => 'Discount / promo codes (the redemption ledger and counters are never copied).',
            self::Dunning => 'Per-decline-category adaptive dunning strategies.',
            self::Experiments => 'A/B pricing experiments and their variants (config side only).',
        };
    }

    /** Parse a stored/submitted group slug, or null when it names no known group. */
    public static function tryParse(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
