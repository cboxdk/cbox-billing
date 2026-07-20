<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Contracts;

use App\Billing\Cpq\Exceptions\SignatureRejected;
use App\Billing\Cpq\NullSignatureProvider;
use App\Billing\Cpq\ValueObjects\SignatureRequest;
use App\Billing\Cpq\ValueObjects\SignatureResult;
use App\Models\Quote;
use App\Models\QuoteAcceptance;

/**
 * The e-signature seam for accepting a quote's order form. The DEFAULT implementation
 * ({@see NullSignatureProvider}) is an in-house e-signature-by-acceptance: it
 * verifies the customer typed their full name and ticked the explicit agreement, and treats that —
 * with the server-captured timestamp and IP recorded on the immutable
 * {@see QuoteAcceptance} — as the signature. No document is sent anywhere.
 *
 * This is a deliberate BOUNDARY: a host that wants a real signature provider (DocuSign, Adobe Sign,
 * Scrive, …) binds their own implementation to this contract and returns the provider's envelope
 * reference. This app ships ONLY the null provider and does NOT fabricate a third-party
 * integration. See docs/quotes/order-form-and-acceptance.md → "The signature-provider seam".
 */
interface CapturesSignature
{
    /**
     * Capture the customer's signature for `$quote`. Returns the {@see SignatureResult} recorded on
     * the acceptance; throws {@see SignatureRejected} when the submission does not constitute a
     * valid acceptance (no typed name, or the agreement box unchecked).
     *
     * @throws SignatureRejected
     */
    public function capture(Quote $quote, SignatureRequest $request): SignatureResult;
}
