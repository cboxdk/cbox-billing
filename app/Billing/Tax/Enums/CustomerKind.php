<?php

declare(strict_types=1);

namespace App\Billing\Tax\Enums;

use Cbox\Tax\Enums\CustomerType;

/**
 * Whether an organization buys as a business or as a consumer — the app-side, persisted form of
 * the tax engine's {@see CustomerType}.
 *
 * This exists as its own enum rather than storing the engine's directly because it is a column
 * value with a stable wire form: the engine's enum belongs to a dependency and may grow cases the
 * database has never seen. {@see toTaxCustomer()} is the one translation point.
 *
 * It matters because the distinction drives real money. A cross-border supply to a BUSINESS with a
 * validated VAT ID is reverse-charged (no VAT charged, the buyer accounts for it); the same supply
 * to a CONSUMER is taxed at the consumer's local rate. Treating every buyer as a business — which
 * the tax context did — is only invisible while reverse charge is unreachable.
 */
enum CustomerKind: string
{
    case Business = 'business';
    case Consumer = 'consumer';

    public function toTaxCustomer(): CustomerType
    {
        return match ($this) {
            self::Business => CustomerType::Business,
            self::Consumer => CustomerType::Consumer,
        };
    }

    /** The persisted value, falling back to business — the behaviour that predates the column. */
    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Business;
    }

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business (B2B)',
            self::Consumer => 'Consumer (B2C)',
        };
    }
}
