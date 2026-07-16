<?php

declare(strict_types=1);

namespace App\Billing\Licensing\Exceptions;

use RuntimeException;

/**
 * A licensing operation could not proceed. The two cases callers map to HTTP: a plan that
 * is not licensable (deny-by-default — no profile is declared for it) and an unknown
 * license id (renew/revoke a license that was never issued). The signing-key-missing case
 * is a distinct operator error raised where the key is bound.
 */
class LicensingException extends RuntimeException
{
    public static function nonLicensablePlan(string $planId): self
    {
        return new self(sprintf('Plan [%s] is not licensable — no license profile is declared for it.', $planId));
    }

    public static function unknownLicense(string $licenseId): self
    {
        return new self(sprintf('No issued license found for id [%s].', $licenseId));
    }
}
