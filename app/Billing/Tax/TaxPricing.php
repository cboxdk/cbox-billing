<?php

declare(strict_types=1);

namespace App\Billing\Tax;

use Cbox\Tax\Enums\Pricing;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves whether the seller's catalog prices are quoted tax-EXCLUSIVE (net; tax added on
 * top — the US default) or tax-INCLUSIVE (gross; tax extracted from within — common for EU
 * B2C), from `billing.tax.pricing`. The tax engine prices ONE mode per quote/invoice, so this
 * is the document-level convention; an unknown/misconfigured value falls back to exclusive
 * (deny-by-default — never silently treat a net price as gross).
 */
class TaxPricing
{
    public static function fromConfig(Config $config): Pricing
    {
        return $config->get('billing.tax.pricing') === Pricing::Inclusive->value
            ? Pricing::Inclusive
            : Pricing::Exclusive;
    }
}
