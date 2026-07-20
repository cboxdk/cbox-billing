<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

/**
 * A full authored quote: the header (buyer — an existing org or a pre-account prospect — the
 * selling entity, currency, validity, owner, notes, an optional order coupon), the contract
 * {@see QuoteTermsDraft}, and the ordered {@see QuoteLineDraft} lines. The authoring service maps a
 * console request into this and persists it; the calculator prices it.
 *
 * @property list<QuoteLineDraft> $lines
 */
readonly class QuoteDraft
{
    /**
     * @param  list<QuoteLineDraft>  $lines
     */
    public function __construct(
        public ?string $organizationId,
        public ?string $prospectName,
        public ?string $prospectEmail,
        public ?string $sellerEntityId,
        public string $currency,
        public ?string $validUntil,
        public ?string $notes,
        public ?int $couponId,
        public ?string $ownerSub,
        public ?string $ownerName,
        public QuoteTermsDraft $terms,
        public array $lines,
    ) {}
}
