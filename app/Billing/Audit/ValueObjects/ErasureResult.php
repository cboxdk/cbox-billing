<?php

declare(strict_types=1);

namespace App\Billing\Audit\ValueObjects;

/**
 * The outcome of a right-to-be-forgotten erasure: which PII fields were redacted to tombstones,
 * how many certificate documents were deleted from disk, how many gateway-customer mappings were
 * detached, and — for honesty in the UI — which financial record classes were RETAINED (and thus
 * NOT erased) under statutory retention. "Erased" here means the PII, never the money trail.
 */
readonly class ErasureResult
{
    /**
     * @param  list<string>  $redactedFields  the org PII fields replaced with tombstones
     * @param  array<string, int>  $retained  retained record class → count left intact (de-identified)
     */
    public function __construct(
        public string $organizationId,
        public array $redactedFields,
        public int $certificateDocumentsDeleted,
        public int $gatewayMappingsDetached,
        public array $retained,
    ) {}
}
