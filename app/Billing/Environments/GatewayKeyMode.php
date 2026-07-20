<?php

declare(strict_types=1);

namespace App\Billing\Environments;

use App\Billing\Mode\BillingMode;
use App\Billing\TestMode\ModeAwarePaymentGateway;
use App\Billing\TestMode\TestPaymentGateway;
use App\Models\Environment;

/**
 * Which set of payment-gateway credentials a billing {@see Environment} charges
 * through. LIVE routes to the configured real gateway (real money); TEST routes to the fake
 * {@see TestPaymentGateway} and can never reach a real account. It is the
 * environment attribute the {@see ModeAwarePaymentGateway} keys off, and
 * the transitional bridge to the legacy {@see BillingMode} (test/live) that API tokens still
 * carry.
 *
 * (Gateway-key VALIDATION — refusing to create a production environment without live keys — is a
 * later wave; this enum is only the mode selector and leaves that seam open.)
 */
enum GatewayKeyMode: string
{
    case Live = 'live';
    case Test = 'test';

    public function isTest(): bool
    {
        return $this === self::Test;
    }

    /** The legacy plane enum this key mode bridges to (live ↔ live plane, test ↔ sandbox). */
    public function billingMode(): BillingMode
    {
        return $this === self::Live ? BillingMode::Live : BillingMode::Test;
    }

    /** Parse a stored string, deny-by-default to TEST (never route an unknown value at the real gateway). */
    public static function parse(?string $value): self
    {
        return $value === self::Live->value ? self::Live : self::Test;
    }
}
