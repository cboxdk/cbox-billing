<?php

declare(strict_types=1);

namespace App\Billing\Licensing\Contracts;

use App\Billing\Licensing\Exceptions\LicensingException;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use DateTimeImmutable;

/**
 * The issuer-side lifecycle of an on-prem license — mint, renew, revoke. The concrete
 * service is the one place billing's own request is turned into the crypto core's signed
 * artifact and persisted; controllers and commands depend on this contract, never on the
 * mint or the key holders directly.
 *
 * Issuing is deny-by-default: a plan with no declared license profile cannot be minted
 * ({@see LicensingException}).
 */
interface IssuesLicenses
{
    /**
     * Mint one signed license for `$customerId` on the licensable `$planId`, bound to
     * `$deploymentId` (generated when omitted) and optionally pinned to `$licensedDomain`.
     * The validity window runs from now to `$expiresAt` (or now + the configured
     * `validity_days` when omitted). Throws when the plan is not licensable.
     */
    public function issue(
        string $customerId,
        string $planId,
        ?string $deploymentId = null,
        ?string $licensedDomain = null,
        ?DateTimeImmutable $expiresAt = null,
    ): IssuedLicense;

    /**
     * Re-mint the license `$licenseId` under a fresh id for the SAME deployment, customer,
     * plan and bindings with an extended expiry (`$expiresAt`, or the existing expiry
     * pushed out by the configured `validity_days`). Throws when the id is unknown.
     */
    public function renew(string $licenseId, ?DateTimeImmutable $expiresAt = null): IssuedLicense;

    /**
     * Add `$licenseId` to the revocation registry (idempotent) so the next signed
     * revocation list refuses it. `$reason` is an operator note, never signed into the list.
     */
    public function revoke(string $licenseId, ?string $reason = null): void;
}
