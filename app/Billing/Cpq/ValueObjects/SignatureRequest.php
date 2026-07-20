<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

use App\Billing\Cpq\Contracts\CapturesSignature;

/**
 * What the customer submitted on the order form to accept a quote: the typed full name, an
 * optional email, the explicit agreement flag, and the request context the server captured (IP,
 * user agent). This is the input to the {@see CapturesSignature} seam.
 */
readonly class SignatureRequest
{
    public function __construct(
        public string $signerName,
        public ?string $signerEmail,
        public bool $agreed,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
