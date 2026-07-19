<?php

declare(strict_types=1);

namespace App\Billing\Invoicing\Exceptions;

use RuntimeException;

/**
 * A guarded invoice lifecycle action was refused server-side (Wave 3): voiding a paid
 * invoice, refunding an unissued one, an over-refund, or creating an ad-hoc invoice with
 * no billable lines. The controller catches it and flashes the reason back — the invoice
 * is left exactly as it was. The confirm dialog is UX only; THIS is the enforcement.
 */
class InvoiceActionDenied extends RuntimeException
{
    public static function notVoidable(string $status): self
    {
        return new self(sprintf('Only an open or uncollectible invoice can be voided (this one is %s).', $status));
    }

    public static function alreadyVoided(): self
    {
        return new self('This invoice is already voided.');
    }

    public static function notRefundable(string $status): self
    {
        return new self(sprintf('Only an issued invoice can be refunded (this one is %s).', $status));
    }

    public static function noLines(): self
    {
        return new self('An invoice needs at least one line with a positive amount.');
    }

    public static function taxPending(string $reason): self
    {
        return new self(sprintf('Cannot issue this invoice: tax is pending (%s). Set a billing address first.', $reason));
    }
}
