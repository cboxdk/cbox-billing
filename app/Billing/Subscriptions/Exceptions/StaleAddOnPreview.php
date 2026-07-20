<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\Exceptions;

use RuntimeException;

/**
 * An add-on apply was refused because the client-supplied expected "due now" no longer matches the
 * freshly-computed prorated gross — the preview and the confirm straddled a period boundary (or the
 * clock advanced), so charging would collect a different amount than the customer was shown. The
 * confirm is rejected (surfaced as a 409) rather than silently charging a drifted amount; the
 * caller re-previews and re-confirms against the current period.
 */
class StaleAddOnPreview extends RuntimeException
{
    public static function mismatch(int $expectedMinor, int $actualMinor, string $currency): self
    {
        return new self(sprintf(
            'The add-on preview is stale: expected %d but the current charge is %d (%s). Re-preview and try again.',
            $expectedMinor,
            $actualMinor,
            $currency,
        ));
    }
}
