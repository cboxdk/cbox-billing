<?php

declare(strict_types=1);

namespace App\Billing\Import\Enums;

use App\Billing\Import\Contracts\SourceAdapter;

/**
 * The billing providers a seller can migrate off. Each maps to a concrete
 * {@see SourceAdapter} that parses that provider's export files
 * (field-names + units + date formats differ per provider) into the app's normalized model.
 * Deny-by-default: an unrecognised source string is refused, never guessed.
 */
enum ImportSource: string
{
    case Stripe = 'stripe';
    case Chargebee = 'chargebee';
    case Recurly = 'recurly';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Chargebee => 'Chargebee',
            self::Recurly => 'Recurly',
        };
    }

    /** Parse a stored/request string, or null when it names no supported source. */
    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
