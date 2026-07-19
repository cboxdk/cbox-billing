<?php

declare(strict_types=1);

namespace App\Billing\Audit\Contracts;

use App\Billing\Audit\ValueObjects\ErasureResult;
use App\Models\Organization;

/**
 * The right-to-be-forgotten action, honouring the financial-records retention tension. It does
 * NOT hard-delete: invoices, credit notes and the ledger MUST be retained for statutory periods,
 * so erasure PSEUDONYMIZES the subject's PII in place (name, email, tax id, addresses, stored
 * certificate documents, gateway PII) while leaving the legally-required financial documents
 * intact in a de-identified form. It records an erasure audit event and marks the org `erased`.
 */
interface RedactsSubjectData
{
    /** Redact the subject's PII, retain its financial records, and return what was done. */
    public function erase(Organization $organization): ErasureResult;
}
