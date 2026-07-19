<?php

declare(strict_types=1);

namespace App\Billing\Tax\Exemptions;

use App\Billing\Tax\TaxContextFactory;
use App\Models\Organization;
use App\Models\TaxExemptionCertificate;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\ValueObjects\TaxQuery;
use Illuminate\Support\Collection;

/**
 * The bridge between an organization's exemption certificates and the (org-blind) tax
 * calculator. The tax engine's {@see TaxQuery} carries the place of
 * supply but not the buyer, so the exemption decision — which is a property of *(buyer,
 * jurisdiction)* — is loaded here, per organization, right before its quote is built (in
 * {@see TaxContextFactory::forOrganization()}), and read by the
 * {@see ExemptingTaxCalculator} decorator while it assesses each line.
 *
 * Request-scoped and synchronous by construction: a quote is always built immediately after
 * its context is activated, so the active set never straddles two organizations. Bound as a
 * singleton so the factory and the decorator share one instance.
 */
class ExemptionContext
{
    /** @var Collection<int, TaxExemptionCertificate>|null */
    private ?Collection $active = null;

    private ?TaxExemptionCertificate $applied = null;

    /**
     * Load the organization's currently-exempting certificates (verified, non-expired) as the
     * active set the decorator will consult, and reset the applied marker for the build that
     * follows.
     */
    public function activate(Organization $organization): void
    {
        $this->active = TaxExemptionCertificate::query()
            ->where('organization_id', $organization->id)
            ->active()
            ->get();

        $this->applied = null;
    }

    /** Forget the active set — no organization is exempt until the next {@see activate()}. */
    public function clear(): void
    {
        $this->active = null;
        $this->applied = null;
    }

    /**
     * The active certificate that covers `$place`, or null when the current organization has
     * none for that jurisdiction. Re-checks {@see TaxExemptionCertificate::isActiveNow()} so a
     * certificate that expired between load and use never exempts.
     */
    public function certificateCovering(Jurisdiction $place): ?TaxExemptionCertificate
    {
        if ($this->active === null) {
            return null;
        }

        foreach ($this->active as $certificate) {
            if ($certificate->isActiveNow() && $certificate->coversPlace($place)) {
                return $certificate;
            }
        }

        return null;
    }

    /** Record that a certificate was applied during the current build (for the invoice stamp). */
    public function markApplied(TaxExemptionCertificate $certificate): void
    {
        $this->applied = $certificate;
    }

    /** The certificate that exempted the most recent build, or null when none did. */
    public function appliedCertificate(): ?TaxExemptionCertificate
    {
        return $this->applied;
    }
}
