<?php

declare(strict_types=1);

namespace App\Billing\Environments\Gateways;

use App\Billing\Environments\GatewayKeyMode;
use RuntimeException;

/**
 * Raised when per-environment gateway credentials are rejected on save: an unsupported gateway,
 * or — the safety gate — a Stripe key whose TYPE does not match the plane's
 * {@see GatewayKeyMode}. A sandbox (test plane) may only hold test keys and production may only
 * hold live keys, so a real card can never be charged from a sandbox and a test key can never be
 * mistaken for a production credential.
 */
class GatewayCredentialException extends RuntimeException
{
    public static function unsupportedGateway(string $gateway): self
    {
        return new self(sprintf('Unsupported payment gateway “%s”. Only per-environment Stripe credentials are supported.', $gateway));
    }

    public static function wrongKeyType(GatewayKeyMode $mode): self
    {
        return $mode === GatewayKeyMode::Live
            ? new self('This is the PRODUCTION environment — it requires a live Stripe secret key (sk_live_… / rk_live_…). A test key is refused so production always charges the real account.')
            : new self('This is a SANDBOX (test) environment — it accepts only a test Stripe secret key (sk_test_… / rk_test_…). A live key is refused so a real card can never be charged in a sandbox.');
    }

    public static function wrongPublishableType(GatewayKeyMode $mode): self
    {
        return $mode === GatewayKeyMode::Live
            ? new self('The production environment requires a live publishable key (pk_live_…).')
            : new self('A sandbox environment accepts only a test publishable key (pk_test_…).');
    }

    public static function emptySecret(): self
    {
        return new self('A Stripe secret key is required.');
    }
}
