<?php

declare(strict_types=1);

namespace App\Billing\Catalog\Exceptions;

use RuntimeException;

/**
 * Raised when a catalog CRUD action is refused by a referential-integrity guard — a
 * hard-delete that would orphan history or break a live subscriber (a product that still
 * groups plans, a plan with subscribers, a price a serving subscriber bills on, a meter an
 * entitlement or usage references). The console controllers catch it and flash the reason
 * back, so the guard is enforced server-side and never relies on the confirm dialog alone.
 *
 * These guards preserve grandfathering and the currency-lock invariant: an authored edit
 * or archive never removes the price version a subscriber grandfathered onto, so nobody is
 * silently repriced or left billing in a currency the plan no longer carries.
 */
class CatalogActionDenied extends RuntimeException
{
    public static function productHasPlans(string $name, int $plans): self
    {
        return new self(sprintf(
            '%s still has %d plan%s. Archive it instead, or remove its plans first.',
            $name,
            $plans,
            $plans === 1 ? '' : 's',
        ));
    }

    public static function planHasSubscribers(string $name, int $subscribers): self
    {
        return new self(sprintf(
            '%s has %d subscriber%s. Archive it instead — archiving keeps them on their grandfathered price.',
            $name,
            $subscribers,
            $subscribers === 1 ? '' : 's',
        ));
    }

    public static function priceInUse(string $plan, string $currency, int $subscribers): self
    {
        return new self(sprintf(
            'The %s %s price is billing %d serving subscriber%s. Removing it would break their grandfathered currency — move them first.',
            $plan,
            $currency,
            $subscribers,
            $subscribers === 1 ? '' : 's',
        ));
    }

    public static function meterReferenced(string $name, int $entitlements): self
    {
        return new self(sprintf(
            '%s is referenced by %d plan entitlement%s. Archive it instead — archiving keeps its historical policy resolving.',
            $name,
            $entitlements,
            $entitlements === 1 ? '' : 's',
        ));
    }

    public static function meterHasUsage(string $name): self
    {
        return new self(sprintf(
            '%s has recorded usage events. Archive it instead of deleting so its history is preserved.',
            $name,
        ));
    }

    public static function duplicateKey(string $key): self
    {
        return new self(sprintf('The key "%s" is already in use. Keys must be unique.', $key));
    }

    public static function unbillableInterval(string $interval): self
    {
        return new self(sprintf(
            'Interval "%s" cannot be billed — the engine renews only month and year cadences. Author the plan as month or year.',
            $interval,
        ));
    }

    public static function unknownMeter(int $meterId): self
    {
        return new self(sprintf('Meter [%d] does not exist.', $meterId));
    }

    public static function featureReferenced(string $name, int $references): self
    {
        return new self(sprintf(
            '%s is referenced by %d plan grant%s. Archive it instead — archiving keeps existing grants resolving.',
            $name,
            $references,
            $references === 1 ? '' : 's',
        ));
    }

    public static function unknownFeature(int $featureId): self
    {
        return new self(sprintf('Feature [%d] does not exist.', $featureId));
    }
}
