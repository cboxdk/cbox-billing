<?php

declare(strict_types=1);

namespace App\Billing\Tax\Enums;

/**
 * The result of consulting a tax-ID register.
 *
 * `Invalid` and `Inconclusive` are deliberately distinct even though both leave the customer
 * unvalidated and therefore taxed. They mean opposite things operationally: `Invalid` is the
 * register answering "no", which the customer must fix; `Inconclusive` is the register not
 * answering at all, which resolves itself on retry. Collapsing them — which a bare boolean does —
 * is what turns a transient VIES outage into a support ticket about being wrongly charged VAT.
 */
enum TaxIdVerdict: string
{
    /** The register confirmed the ID. A cross-border B2B supply may be reverse-charged. */
    case Valid = 'valid';

    /** The register answered, and says this ID is not valid. */
    case Invalid = 'invalid';

    /** The register could not be reached or would not decide. Unvalidated, but retryable. */
    case Inconclusive = 'inconclusive';

    /** No register is available for that country (outside the EU/UK schemes we can consult). */
    case Unsupported = 'unsupported';

    /** There is no tax ID on file to check. */
    case NotProvided = 'not_provided';

    /** Whether trying again later could plausibly change the answer. */
    public function isRetryable(): bool
    {
        return $this === self::Inconclusive;
    }
}
