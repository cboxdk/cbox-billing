<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ApprovalContext;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Billing\Approvals\ValueObjects\ApprovalOutcome;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Catalog\PlanAuthoring;
use App\Models\Plan;

/**
 * Held action for archiving a plan (closing it to new signups; existing subscribers keep their
 * grandfathered price). {@see execute()} calls the SAME {@see PlanAuthoring::archive()} the
 * direct catalog path uses and records the `plan.archived` audit event, so an approved archival
 * is identical to a direct one. Catalog authoring is not org-scoped, so there is no money
 * dimension — enabling this action means every archival needs a second person.
 */
readonly class ArchivePlanAction implements ApprovableAction
{
    public function __construct(
        private PlanAuthoring $authoring,
        private RecordsAudit $audit,
        private Plan $plan,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::PlanArchive;
    }

    public function context(): ApprovalContext
    {
        return new ApprovalContext(
            organizationId: null,
            amountMinor: null,
            currency: null,
            targetType: 'plan',
            targetId: (string) $this->plan->id,
        );
    }

    public function payload(): array
    {
        return ['plan_id' => $this->plan->id];
    }

    public function validate(): void
    {
        // Archiving an already-archived plan is idempotent; nothing to refuse.
    }

    public function describe(): ApprovalDescription
    {
        return new ApprovalDescription(
            sprintf('Archive plan “%s” — closed to new signups.', $this->plan->name),
            before: ['active' => (bool) $this->plan->active],
            after: ['active' => false],
        );
    }

    public function execute(): ApprovalOutcome
    {
        $this->authoring->archive($this->plan);

        $this->audit->record(
            AuditAction::PlanArchived,
            AuditTarget::model($this->plan),
            sprintf('Archived plan “%s” — closed to new signups.', $this->plan->name),
            ['before' => ['active' => true], 'after' => ['active' => false]],
        );

        return new ApprovalOutcome(
            sprintf('Plan “%s” archived.', $this->plan->name),
            ['plan_id' => $this->plan->id],
        );
    }
}
