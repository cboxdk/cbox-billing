<?php

declare(strict_types=1);

namespace App\Billing\Environments\Gateways;

use App\Billing\Environments\GatewayKeyMode;

/**
 * The Stripe-style key-type gate (the safety gate). Validates that a Stripe secret (and, when
 * given, publishable) key matches the plane's {@see GatewayKeyMode}: a TEST plane accepts only
 * test keys (`sk_test_` / `rk_test_`, `pk_test_`) and a LIVE plane only live keys (`sk_live_` /
 * `rk_live_`, `pk_live_`). This is what stops a real card being charged in a sandbox and stops a
 * test key masquerading as a production credential — the wrong type is rejected with a clear,
 * mode-specific message before anything is persisted.
 *
 * Restricted-key prefixes (`rk_`) are accepted alongside the standard secret prefix (`sk_`) so a
 * least-privilege restricted key still validates. The webhook signing secret (`whsec_…`) carries
 * no test/live discriminator in Stripe, so it is not type-gated here.
 */
readonly class StripeKeyValidator
{
    /**
     * @throws GatewayCredentialException when the secret is empty or its type does not match `$mode`
     */
    public function validateSecret(string $secret, GatewayKeyMode $mode): void
    {
        $secret = trim($secret);

        if ($secret === '') {
            throw GatewayCredentialException::emptySecret();
        }

        if (! $this->matchesMode($secret, $mode, ['sk_', 'rk_'])) {
            throw GatewayCredentialException::wrongKeyType($mode);
        }
    }

    /**
     * @throws GatewayCredentialException when a non-empty publishable key's type does not match `$mode`
     */
    public function validatePublishable(?string $publishable, GatewayKeyMode $mode): void
    {
        $publishable = trim((string) $publishable);

        if ($publishable === '') {
            return; // publishable is optional
        }

        if (! $this->matchesMode($publishable, $mode, ['pk_'])) {
            throw GatewayCredentialException::wrongPublishableType($mode);
        }
    }

    /**
     * Whether `$key` starts with one of `$prefixes` followed by the mode segment
     * (`test_` for a sandbox, `live_` for production).
     *
     * @param  list<string>  $prefixes
     */
    private function matchesMode(string $key, GatewayKeyMode $mode, array $prefixes): bool
    {
        $segment = $mode === GatewayKeyMode::Live ? 'live_' : 'test_';

        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix.$segment)) {
                return true;
            }
        }

        return false;
    }
}
