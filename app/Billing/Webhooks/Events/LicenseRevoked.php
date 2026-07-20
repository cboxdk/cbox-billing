<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Events;

use App\Billing\Licensing\DatabaseRevocationRegistry;

/**
 * A license was revoked. Raised by {@see DatabaseRevocationRegistry::revoke()}
 * on the first (non-idempotent) revoke of a license id, to feed `license.revoked`. A repeat revoke
 * of an already-revoked id is a no-op and does not re-fire.
 */
readonly class LicenseRevoked
{
    public function __construct(
        public string $licenseId,
        public ?string $reason,
    ) {}
}
