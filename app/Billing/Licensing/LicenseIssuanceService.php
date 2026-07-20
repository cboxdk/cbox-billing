<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use App\Billing\Licensing\Exceptions\LicensingException;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Models\Organization;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\Billing\Licensing\LicenseMint;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\Billing\Licensing\ValueObjects\LicenseIssuanceRequest;
use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mints, renews and revokes on-prem licenses. This is the one place billing turns a
 * licensable plan into a signed, offline-verifiable artifact and persists it: it resolves
 * the plan's {@see LicenseProfile} (deny-by-default),
 * sizes the validity window from config, signs via the engine {@see LicenseMint} (which
 * holds no key — the host-bound crypto core does), and stores the record.
 *
 * The service is key-agnostic: it depends on the engine's key-agnostic mint and the
 * durable ports, so it never sees the signing key.
 */
readonly class LicenseIssuanceService implements IssuesLicenses
{
    public function __construct(
        private LicenseMint $mint,
        private IssuedLicenseStore $store,
        private LicenseProfileResolver $profiles,
        private LicenseRevocationRegistry $revocations,
        private Config $config,
        private NotifiesCustomers $notifier,
        private RecordsAudit $audit,
    ) {}

    public function issue(
        string $customerId,
        string $planId,
        ?string $deploymentId = null,
        ?string $licensedDomain = null,
        ?DateTimeImmutable $expiresAt = null,
    ): IssuedLicense {
        $profile = $this->profiles->resolve($planId);

        if ($profile === null) {
            throw LicensingException::nonLicensablePlan($planId);
        }

        $now = $this->now();
        $deployment = $deploymentId ?? self::generateDeploymentId();

        $issued = $this->mint->issue(new LicenseIssuanceRequest(
            customerId: $customerId,
            deploymentId: $deployment,
            profile: $profile,
            notBefore: $now,
            expiresAt: $expiresAt ?? $now->add($this->validity()),
            licensedDomain: $licensedDomain,
        ), $now);

        $this->store->save($issued);

        // Record the FACT + references — never the signed license key. Covers every surface
        // (console, token API, scheduled issuance) through this one shared seam.
        $this->audit->record(
            AuditAction::LicenseIssued,
            AuditTarget::of('license', $issued->id, $customerId),
            sprintf('Issued license %s for plan %s.', $issued->id, $planId),
            ['plan' => $planId, 'deployment_id' => $deployment, 'expires_at' => $issued->expiresAt->format(DateTimeImmutable::ATOM)],
        );

        $this->deliver($issued, reissued: false);

        return $issued;
    }

    public function renew(string $licenseId, ?DateTimeImmutable $expiresAt = null): IssuedLicense
    {
        $existing = $this->store->find($licenseId);

        if ($existing === null) {
            throw LicensingException::unknownLicense($licenseId);
        }

        $renewed = $this->mint->reissue(
            $existing,
            $expiresAt ?? $existing->expiresAt->add($this->validity()),
            $this->now(),
        );

        $this->store->save($renewed);

        $this->audit->record(
            AuditAction::LicenseRenewed,
            AuditTarget::of('license', $renewed->id, $renewed->customerId),
            sprintf('Renewed license %s.', $renewed->id),
            ['expires_at' => $renewed->expiresAt->format(DateTimeImmutable::ATOM)],
        );

        $this->deliver($renewed, reissued: true);

        return $renewed;
    }

    public function revoke(string $licenseId, ?string $reason = null): void
    {
        $this->revocations->revoke($licenseId, $reason);

        $this->audit->record(
            AuditAction::LicenseRevoked,
            AuditTarget::of('license', $licenseId),
            sprintf('Revoked license %s.', $licenseId),
            ['reason' => $reason],
        );
    }

    /**
     * Email the copy-pasteable license key + install notes to the customer's billing
     * contact. The license's `customerId` is the organization id; when it maps to no known
     * org (a bare-id issue) delivery is skipped rather than sent to a fabricated recipient.
     */
    private function deliver(IssuedLicense $license, bool $reissued): void
    {
        $organization = Organization::query()->find($license->customerId);

        if ($organization instanceof Organization) {
            $this->notifier->licenseDelivered($organization, $license, $reissued);
        }
    }

    /** The configured default validity window as a date interval. */
    private function validity(): DateInterval
    {
        $days = $this->config->get('billing.licensing.validity_days', 365);

        return new DateInterval('P'.(is_numeric($days) ? (int) $days : 365).'D');
    }

    private function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }

    private static function generateDeploymentId(): string
    {
        return 'dep_'.Str::lower(Str::random(24));
    }
}
