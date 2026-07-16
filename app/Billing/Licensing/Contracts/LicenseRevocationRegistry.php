<?php

declare(strict_types=1);

namespace App\Billing\Licensing\Contracts;

use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\Billing\Licensing\RevocationPublisher;

/**
 * The app's durable revocation registry. It satisfies the engine's
 * {@see RevocationRegistry} (which the {@see RevocationPublisher}
 * reads to cut the signed list) and adds the two things the console/API surface needs
 * that the minimal engine contract does not expose: an optional operator `reason` on
 * revoke, and a membership check so a screen can flag a license as revoked.
 *
 * Overriding `revoke()` with an extra optional parameter keeps it a valid engine
 * {@see RevocationRegistry} — the publisher still calls the one-argument form.
 */
interface LicenseRevocationRegistry extends RevocationRegistry
{
    public function revoke(string $licenseId, ?string $reason = null): void;

    public function isRevoked(string $licenseId): bool;
}
