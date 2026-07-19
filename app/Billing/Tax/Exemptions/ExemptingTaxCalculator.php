<?php

declare(strict_types=1);

namespace App\Billing\Tax\Exemptions;

use Brick\Money\Money;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Enums\TaxTreatment;
use Cbox\Tax\ValueObjects\TaxAssessment;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * Decorates the engine's {@see TaxCalculator} with customer exemption certificates. It feeds
 * the exemption in as a zero-rate/exempt DECISION — it never hand-rolls tax math:
 *
 *  1. Ask the wrapped calculator for its verdict (nexus, taxability, rate — all its logic).
 *  2. Only when that verdict WOULD charge tax ({@see TaxTreatment::Standard}) AND the buyer
 *     holds a verified, non-expired certificate covering that jurisdiction, flip the line to
 *     {@see TaxTreatment::Exempt}: the engine-computed net is kept, the tax becomes zero and
 *     gross equals net — exactly the shape the engine's own exempt branch produces.
 *
 * Everything else is returned untouched: EU VAT reverse-charge, a not-registered jurisdiction
 * (already zero), and lines the buyer holds no matching certificate for. Deny-by-default: no
 * active certificate covering the place of supply means the line is taxed as assessed.
 */
readonly class ExemptingTaxCalculator implements TaxCalculator
{
    public function __construct(
        private TaxCalculator $inner,
        private ExemptionContext $exemptions,
    ) {}

    public function assess(TaxQuery $query): TaxAssessment
    {
        $assessment = $this->inner->assess($query);

        // Only a treatment that actually collects tax can be exempted. Reverse-charge,
        // not-registered and already-exempt/zero-rated verdicts are left exactly as the
        // engine returned them — a certificate never changes those.
        if (! $assessment->treatment->chargesTax()) {
            return $assessment;
        }

        $certificate = $this->exemptions->certificateCovering($query->place);

        if ($certificate === null) {
            return $assessment;
        }

        $this->exemptions->markApplied($certificate);

        return new TaxAssessment(
            treatment: TaxTreatment::Exempt,
            net: $assessment->net,
            tax: Money::zero($assessment->net->getCurrency()),
            gross: $assessment->net,
            placeOfSupply: $assessment->placeOfSupply,
            rate: null,
            reason: $certificate->exemptionReason(),
        );
    }
}
