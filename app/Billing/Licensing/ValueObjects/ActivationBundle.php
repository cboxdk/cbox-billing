<?php

declare(strict_types=1);

namespace App\Billing\Licensing\ValueObjects;

use DateTimeImmutable;

/**
 * What the optional online activation heartbeat returns to a self-hosted deployment: the
 * latest signed license for its deployment id (in case it was reissued), the current
 * signed revocation list, and the issuer public key. Offline installs never fetch this —
 * they verify the license they were handed against the bundled public key with no call
 * home; this is only the refresh path for deployments that choose to check in.
 */
readonly class ActivationBundle
{
    public function __construct(
        public string $deploymentId,
        public string $licenseId,
        public string $licenseKey,
        public DateTimeImmutable $expiresAt,
        public string $revocationList,
        public string $publicKey,
    ) {}
}
