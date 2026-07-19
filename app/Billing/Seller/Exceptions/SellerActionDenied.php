<?php

declare(strict_types=1);

namespace App\Billing\Seller\Exceptions;

use RuntimeException;

/**
 * Raised when a seller-entity CRUD action is refused by a referential-integrity guard — a
 * hard-delete that would orphan the legal record (a seller whose `invoice_prefix` still
 * numbers finalized invoices), removing the last/default entity, or a duplicate id. The
 * controller catches it and flashes the reason back, so the guard is enforced server-side
 * and never relies on the confirm dialog alone.
 */
class SellerActionDenied extends RuntimeException
{
    public static function referencedByInvoices(string $name, int $invoices): self
    {
        return new self(sprintf(
            '%s has issued %d invoice%s. Archive it instead — the legal record must survive.',
            $name,
            $invoices,
            $invoices === 1 ? '' : 's',
        ));
    }

    public static function isDefault(string $name): self
    {
        return new self(sprintf('%s is the default selling entity. Make another entity the default first.', $name));
    }

    public static function duplicateId(string $id): self
    {
        return new self(sprintf('A selling entity with the id "%s" already exists. Ids must be unique.', $id));
    }
}
