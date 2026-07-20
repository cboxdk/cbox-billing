<?php

declare(strict_types=1);

namespace App\Billing\Import\Enums;

/**
 * The normalized entity kinds an import moves, in dependency order — a product is created
 * before the plans that group under it, a plan before its prices, a customer + plan before the
 * subscription that binds them, and an invoice last (it references a customer and, optionally,
 * a subscription). The order the importer walks entities in is {@see ordered()}.
 */
enum ImportEntityType: string
{
    case Product = 'product';
    case Plan = 'plan';
    case Price = 'price';
    case Coupon = 'coupon';
    case Customer = 'customer';
    case Subscription = 'subscription';
    case Invoice = 'invoice';

    /** A short human label for the console report. */
    public function label(): string
    {
        return match ($this) {
            self::Product => 'Products',
            self::Plan => 'Plans',
            self::Price => 'Prices',
            self::Coupon => 'Coupons',
            self::Customer => 'Customers',
            self::Subscription => 'Subscriptions',
            self::Invoice => 'Invoices',
        };
    }

    /**
     * The dependency-safe processing order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Product,
            self::Plan,
            self::Price,
            self::Coupon,
            self::Customer,
            self::Subscription,
            self::Invoice,
        ];
    }
}
