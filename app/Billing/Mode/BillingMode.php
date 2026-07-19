<?php

declare(strict_types=1);

namespace App\Billing\Mode;

use App\Models\TestClock;

/**
 * The two data planes an integrator's request can act in. LIVE is the real, default plane
 * (real gateway charges, real emails, the scheduler's cadence); TEST is an isolated sandbox
 * dataset — `livemode=false` rows only, a fake gateway, captured (never delivered) mail, and
 * a fast-forwardable {@see TestClock}. The mode is resolved deny-by-default from
 * the credential (a test API token or the console's test-mode toggle) and defaults to LIVE
 * so nothing already built changes behaviour.
 */
enum BillingMode: string
{
    case Live = 'live';
    case Test = 'test';

    /** Whether rows created/read in this mode carry `livemode = true`. */
    public function livemode(): bool
    {
        return $this === self::Live;
    }

    /** The mode a `livemode` flag maps onto (true → live, false → test). */
    public static function fromLivemode(bool $livemode): self
    {
        return $livemode ? self::Live : self::Test;
    }

    /** Parse a stored/credential string, deny-by-default to LIVE for anything unrecognised. */
    public static function parse(?string $value): self
    {
        return $value === self::Test->value ? self::Test : self::Live;
    }

    public function isTest(): bool
    {
        return $this === self::Test;
    }
}
