<?php

declare(strict_types=1);

namespace App\Billing\Environments;

use App\Models\Environment;

/**
 * The kind of a billing {@see Environment}. There is exactly one PRODUCTION
 * environment per install — the real, protected plane that charges real money through live
 * gateway keys — and any number of SANDBOX environments — disposable, isolated datasets that
 * route through the fake gateway and capture (never deliver) mail. The type drives the two
 * hard invariants: production is `protected` (never deletable, always live gateway keys) and
 * maps onto the legacy `livemode = true` plane; a sandbox maps onto `livemode = false`.
 */
enum EnvironmentType: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    /** Whether this environment is the real, money-moving plane (the legacy `livemode = true`). */
    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /** The `livemode` mirror a row in this environment carries (production → true, sandbox → false). */
    public function livemode(): bool
    {
        return $this === self::Production;
    }

    /** The gateway-key mode an environment of this type defaults to when none is set explicitly. */
    public function defaultGatewayKeyMode(): GatewayKeyMode
    {
        return $this === self::Production ? GatewayKeyMode::Live : GatewayKeyMode::Test;
    }

    /** Parse a stored string, deny-by-default to SANDBOX for anything unrecognised (never a stray production). */
    public static function parse(?string $value): self
    {
        return $value === self::Production->value ? self::Production : self::Sandbox;
    }
}
