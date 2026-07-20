<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Cpq\Contracts\CapturesSignature;
use App\Billing\Cpq\Exceptions\SignatureRejected;
use App\Billing\Cpq\ValueObjects\SignatureRequest;
use App\Billing\Cpq\ValueObjects\SignatureResult;
use App\Models\Quote;
use App\Models\QuoteAcceptance;

/**
 * The default, in-house e-signature-by-acceptance provider. A valid acceptance is: a non-empty
 * typed full name AND the explicit agreement box ticked. There is no external call and no document
 * exchange — the acceptance itself, with the timestamp and IP the acceptance service captures on
 * the immutable {@see QuoteAcceptance}, is the signature evidence.
 *
 * This is the honest default, not a stub for a fabricated integration: a host that needs a
 * certificate-backed provider binds their own {@see CapturesSignature} implementation.
 */
readonly class NullSignatureProvider implements CapturesSignature
{
    public function capture(Quote $quote, SignatureRequest $request): SignatureResult
    {
        if (trim($request->signerName) === '') {
            throw SignatureRejected::missingName();
        }

        if (! $request->agreed) {
            throw SignatureRejected::notAgreed();
        }

        return SignatureResult::inHouse();
    }
}
