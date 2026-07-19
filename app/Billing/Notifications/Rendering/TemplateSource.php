<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

/**
 * Which layer of the resolution chain served a template — surfaced in the console so an
 * operator sees at a glance whether a given (event, locale, seller) renders from a shipped
 * default or from an override, and which fallback stepped in when the exact match was absent.
 */
enum TemplateSource: string
{
    case SellerLocale = 'seller_locale';
    case SellerFallback = 'seller_fallback';
    case GlobalLocale = 'global_locale';
    case GlobalFallback = 'global_fallback';
    case ShippedLocale = 'shipped_locale';
    case ShippedFallback = 'shipped_fallback';

    /** Whether this layer is an operator-authored DB override (vs a shipped default). */
    public function isOverride(): bool
    {
        return match ($this) {
            self::SellerLocale, self::SellerFallback, self::GlobalLocale, self::GlobalFallback => true,
            self::ShippedLocale, self::ShippedFallback => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SellerLocale => 'Seller override',
            self::SellerFallback => 'Seller override (fallback locale)',
            self::GlobalLocale => 'Account override',
            self::GlobalFallback => 'Account override (fallback locale)',
            self::ShippedLocale => 'Shipped default',
            self::ShippedFallback => 'Shipped default (fallback locale)',
        };
    }
}
