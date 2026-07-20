<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Exceptions;

use RuntimeException;

/**
 * A quote lifecycle action was refused because the quote is in the wrong state or the action
 * would violate an invariant (deny-by-default). The message is operator-facing.
 */
class QuoteActionDenied extends RuntimeException
{
    public static function notEditable(): self
    {
        return new self('Only a draft quote can be edited.');
    }

    public static function needsLine(): self
    {
        return new self('A quote needs at least one line before it can be submitted.');
    }

    public static function planNotPriced(string $plan, string $currency): self
    {
        return new self(sprintf('%s is not priced in %s.', $plan, $currency));
    }

    public static function notPendingApproval(): self
    {
        return new self('Only a quote pending approval can be approved or rejected.');
    }

    public static function selfApproval(): self
    {
        return new self('You cannot approve your own quote — approval requires a second operator (two-person rule).');
    }

    public static function notSendable(): self
    {
        return new self('Only an approved quote can be sent.');
    }

    public static function notOpen(): self
    {
        return new self('This quote is not open for a customer decision.');
    }

    public static function alreadyProvisioned(): self
    {
        return new self('This quote has already provisioned its subscription.');
    }

    public static function needsOrganization(): self
    {
        return new self('A quote must be linked to a billing organization before it can provision a subscription.');
    }

    public static function needsPlanLine(): self
    {
        return new self('A quote needs at least one plan line to provision a subscription.');
    }
}
