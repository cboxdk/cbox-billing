<?php

declare(strict_types=1);

namespace App\Billing\Tax;

use App\Billing\Tax\Contracts\VerifiesCustomerTaxIds;
use App\Billing\Tax\Enums\TaxIdVerdict;
use App\Billing\Tax\ValueObjects\TaxIdVerification;
use App\Models\Organization;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies a customer's tax ID against the authoritative register and records the verdict, the
 * evidence and the instant on the organization.
 *
 * The engine already ships working VIES and HMRC validators, bound behind
 * {@see VatIdValidator} — the app simply never resolved them, which is why
 * `organizations.tax_id_validated` was false for every customer that ever existed and EU B2B
 * reverse charge was unreachable.
 *
 * FAILS TOWARD CHARGING VAT. Every path that is not an affirmative "this ID is valid" leaves the
 * org unvalidated, so VAT is charged. That asymmetry is deliberate: over-charging is visible to
 * the customer and refundable, while wrongly zero-rating a cross-border supply leaves the seller
 * owing tax it never collected and cannot easily recover. A register outage must therefore not
 * quietly grant reverse charge.
 */
readonly class RegisterTaxIdVerifier implements VerifiesCustomerTaxIds
{
    public function __construct(private VatIdValidator $validator) {}

    public function verify(Organization $organization): TaxIdVerification
    {
        $taxId = $organization->tax_id;
        $countryCode = $organization->billing_country;

        if (! is_string($taxId) || trim($taxId) === '') {
            return $this->record($organization, new TaxIdVerification(TaxIdVerdict::NotProvided));
        }

        if (! is_string($countryCode) || $countryCode === '') {
            // Without a country there is no register to ask. Not an error — an org can be created
            // before its address is known — but it cannot be validated either.
            return $this->record($organization, new TaxIdVerification(TaxIdVerdict::Unsupported));
        }

        try {
            $country = new CountryCode($countryCode);
        } catch (Throwable) {
            return $this->record($organization, new TaxIdVerification(TaxIdVerdict::Unsupported));
        }

        if (! $this->validator->supports($country)) {
            return $this->record($organization, new TaxIdVerification(TaxIdVerdict::Unsupported));
        }

        try {
            $result = $this->validator->validate($country, trim($taxId));
        } catch (Throwable $e) {
            // A thrown validator is an outage, not a verdict. Treated as inconclusive so the
            // customer keeps being charged VAT and the check can simply be retried.
            Log::warning('tax.vat_id.validation_failed', [
                'organization' => $organization->getKey(),
                'country' => $country->value,
                'error' => $e->getMessage(),
            ]);

            return $this->record($organization, new TaxIdVerification(TaxIdVerdict::Inconclusive, strtolower($country->value)));
        }

        $verdict = match (true) {
            $result->valid && $result->conclusive => TaxIdVerdict::Valid,
            $result->conclusive => TaxIdVerdict::Invalid,
            default => TaxIdVerdict::Inconclusive,
        };

        return $this->record($organization, new TaxIdVerification(
            verdict: $verdict,
            source: $result->source,
            reference: $result->consultationReference,
            name: $result->name,
        ));
    }

    /**
     * Persist the outcome. Evidence is written only for an affirmative verdict and CLEARED
     * otherwise, so a stale consultation reference can never outlive the validation it evidenced —
     * a reference for a VAT ID that has since been deregistered would be worse than none.
     */
    private function record(Organization $organization, TaxIdVerification $verification): TaxIdVerification
    {
        $validated = $verification->isValidated();

        $organization->forceFill([
            'tax_id_validated' => $validated,
            'tax_id_validated_at' => $validated ? Carbon::now() : null,
            'tax_id_validation_reference' => $validated ? $verification->reference : null,
            'tax_id_validation_source' => $validated ? ($verification->source !== '' ? $verification->source : null) : null,
        ])->save();

        return $verification;
    }
}
