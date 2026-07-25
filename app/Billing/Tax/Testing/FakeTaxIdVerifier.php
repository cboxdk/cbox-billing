<?php

declare(strict_types=1);

namespace App\Billing\Tax\Testing;

use App\Billing\Tax\Contracts\VerifiesCustomerTaxIds;
use App\Billing\Tax\Enums\TaxIdVerdict;
use App\Billing\Tax\ValueObjects\TaxIdVerification;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * A scripted {@see VerifiesCustomerTaxIds} for tests and local development.
 *
 * Every branch of the real verifier — valid, invalid, inconclusive, unsupported — decides whether
 * a cross-border supply is reverse-charged, so all four have to be drivable without reaching VIES.
 * A test that can only exercise the happy path would leave the outage behaviour (which must fail
 * toward charging VAT) unproven, and that is the branch with money attached.
 *
 * It writes the same columns the real verifier does, so a test can assert on the persisted
 * evidence rather than on the double.
 */
class FakeTaxIdVerifier implements VerifiesCustomerTaxIds
{
    private TaxIdVerdict $verdict = TaxIdVerdict::Valid;

    private string $source = 'vies';

    private ?string $reference = 'WAPIAAAA_TEST_REF';

    private ?string $name = 'Kunde GmbH';

    /** @var list<string> Organization ids passed to verify(), in order. */
    public array $verified = [];

    public function willReturn(TaxIdVerdict $verdict, string $source = 'vies', ?string $reference = null, ?string $name = null): self
    {
        $this->verdict = $verdict;
        $this->source = $source;
        $this->reference = $reference ?? ($verdict === TaxIdVerdict::Valid ? 'WAPIAAAA_TEST_REF' : null);
        $this->name = $name;

        return $this;
    }

    public function verify(Organization $organization): TaxIdVerification
    {
        $this->verified[] = $organization->id;

        $verification = new TaxIdVerification($this->verdict, $this->source, $this->reference, $this->name);
        $validated = $verification->isValidated();

        $organization->forceFill([
            'tax_id_validated' => $validated,
            'tax_id_validated_at' => $validated ? Carbon::now() : null,
            'tax_id_validation_reference' => $validated ? $verification->reference : null,
            'tax_id_validation_source' => $validated ? $verification->source : null,
        ])->save();

        return $verification;
    }
}
