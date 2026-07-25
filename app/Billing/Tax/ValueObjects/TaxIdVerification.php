<?php

declare(strict_types=1);

namespace App\Billing\Tax\ValueObjects;

use App\Billing\Tax\Enums\TaxIdVerdict;

/**
 * The outcome of checking a customer's tax ID against its country's register, plus the evidence
 * for it.
 *
 * The evidence is not decoration. A zero-rated cross-border supply has to be *provable*: VIES
 * returns a consultation reference precisely so a seller can later show it checked, and a tax
 * authority asking "on what basis did you not charge VAT?" is answered by the reference and the
 * timestamp, not by a boolean.
 */
readonly class TaxIdVerification
{
    public function __construct(
        public TaxIdVerdict $verdict,
        /** The register that answered — `vies`, `hmrc`, … Empty when nothing was consulted. */
        public string $source = '',
        /** The register's proof-of-consultation token, where it issues one. */
        public ?string $reference = null,
        /** The registered name the authority holds, when returned. */
        public ?string $name = null,
    ) {}

    /** Whether this outcome permits a reverse-charged supply. */
    public function isValidated(): bool
    {
        return $this->verdict === TaxIdVerdict::Valid;
    }

    /**
     * An operator-facing explanation. Written to be actionable: "we could not reach the register"
     * and "the register says this ID is not valid" call for completely different responses, and
     * collapsing them into "not validated" is what makes tax problems hard to diagnose.
     */
    public function message(): string
    {
        return match ($this->verdict) {
            TaxIdVerdict::Valid => $this->name !== null
                ? sprintf('VAT ID verified against %s — registered to %s.', strtoupper($this->source), $this->name)
                : sprintf('VAT ID verified against %s.', strtoupper($this->source)),
            TaxIdVerdict::Invalid => sprintf(
                'The %s register does not recognise this VAT ID. VAT will be charged until it verifies.',
                strtoupper($this->source),
            ),
            TaxIdVerdict::Inconclusive => sprintf(
                'The %s register could not be reached, so the VAT ID is unverified for now and VAT will be charged. Try again shortly.',
                strtoupper($this->source),
            ),
            TaxIdVerdict::Unsupported => 'No VAT register is available for this country, so the ID cannot be verified automatically.',
            TaxIdVerdict::NotProvided => 'No tax ID is on file for this customer.',
        };
    }
}
