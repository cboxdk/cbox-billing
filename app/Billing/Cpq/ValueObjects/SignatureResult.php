<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

/**
 * The outcome of capturing a signature: which provider captured it (`null` = the in-house
 * e-sign-by-acceptance), and the provider's own reference for the signed document when there is
 * one (a DocuSign envelope id, etc.). The null provider returns no reference — the acceptance
 * record itself (typed name + agreement + timestamp/IP) is the evidence.
 */
readonly class SignatureResult
{
    public function __construct(
        public string $provider,
        public ?string $reference = null,
    ) {}

    public static function inHouse(): self
    {
        return new self('null');
    }
}
