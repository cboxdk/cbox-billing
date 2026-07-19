<?php

declare(strict_types=1);

namespace App\Billing\TestMode\Enums;

/**
 * The deterministic outcome the fake gateway returns for a test charge. `Succeed` settles the
 * charge (renewal marked paid, subscription stays Active); `Decline` fails it (opens the
 * smart-retry / dunning schedule). Set per test clock so an integrator can drive either path
 * on demand without real money and without a real card.
 */
enum TestChargeOutcome: string
{
    case Succeed = 'succeed';
    case Decline = 'decline';

    /** Parse a stored value, deny-by-default to Succeed for anything unrecognised. */
    public static function parse(?string $value): self
    {
        return $value === self::Decline->value ? self::Decline : self::Succeed;
    }
}
