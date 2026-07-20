<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ApprovalContext;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Billing\Approvals\ValueObjects\ApprovalOutcome;
use App\Billing\Audit\Contracts\RedactsSubjectData;
use App\Models\Organization;
use RuntimeException;

/**
 * Held action for a GDPR right-to-be-forgotten erasure. {@see execute()} runs the SAME
 * {@see RedactsSubjectData} path the direct console erase took — it pseudonymizes the subject's
 * PII and HARD-DELETES its stored certificate documents, retaining the statutory financial
 * records de-identified. Because that document deletion is irreversible, the erase is routed
 * through the approval engine so it needs a second operator (maker-checker), consistent with
 * refunds/suspensions — no single operator can destroy a subject's documents.
 */
readonly class EraseSubjectDataAction implements ApprovableAction
{
    public function __construct(
        private RedactsSubjectData $eraser,
        private Organization $organization,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::DataErase;
    }

    public function context(): ApprovalContext
    {
        return new ApprovalContext(
            organizationId: $this->organization->id,
            amountMinor: null,
            currency: null,
            targetType: 'organization',
            targetId: $this->organization->id,
        );
    }

    public function payload(): array
    {
        return ['organization_id' => $this->organization->id];
    }

    public function validate(): void
    {
        // Erasing an already-erased subject is a no-op that would destroy nothing further; refuse
        // it so a stale held request cannot re-run against a subject that has already been erased.
        if ($this->organization->isErased()) {
            throw new RuntimeException(sprintf('Organization %s is already erased.', $this->organization->id));
        }
    }

    public function describe(): ApprovalDescription
    {
        return new ApprovalDescription(
            sprintf('Erase PII for organization %s (%s) — certificate documents deleted, financial records retained de-identified.', $this->organization->name, $this->organization->id),
            before: ['erased' => false],
            after: ['erased' => true],
        );
    }

    public function execute(): ApprovalOutcome
    {
        $result = $this->eraser->erase($this->organization);

        return new ApprovalOutcome(
            sprintf(
                'Erased PII for %s — %d field(s) redacted, %d certificate document(s) deleted; %d invoice(s) and %d credit note(s) retained (de-identified).',
                $this->organization->id,
                count($result->redactedFields),
                $result->certificateDocumentsDeleted,
                $result->retained['invoices'] ?? 0,
                $result->retained['credit_notes'] ?? 0,
            ),
            [
                'organization_id' => $this->organization->id,
                'redacted_fields' => count($result->redactedFields),
                'certificate_documents_deleted' => $result->certificateDocumentsDeleted,
            ],
        );
    }
}
