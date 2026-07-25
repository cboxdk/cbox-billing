<?php

declare(strict_types=1);

namespace App\Billing\Invoicing\ValueObjects;

/**
 * One VAT rate applied on a legal document, with the taxable amount it applied to and the VAT
 * charged on it — the unit of the per-rate breakdown required by EU Directive 2006/112/EC
 * Art. 226(8)-(10).
 *
 * A document may carry several: a single invoice can mix a standard-rated line with a reduced-rate
 * one, and printing one aggregate "Tax" figure for both is not a valid VAT invoice.
 *
 * Amounts are integer minor units, matching every other money value in the system.
 */
readonly class TaxBand
{
    public function __construct(
        /** The rate as printed, normalised without trailing zeros — "25", "12.5". */
        public string $rate,
        /** The taxable amount this rate applied to, in minor units. */
        public int $net,
        /** The VAT charged on that amount, in minor units. */
        public int $tax,
    ) {}

    /** The same band with a different VAT amount — used when apportioning the document's total. */
    public function withTax(int $tax): self
    {
        return new self($this->rate, $this->net, $tax);
    }

    /** The same band with more taxable amount folded in. */
    public function plusNet(int $net): self
    {
        return new self($this->rate, $this->net + $net, $this->tax);
    }
}
