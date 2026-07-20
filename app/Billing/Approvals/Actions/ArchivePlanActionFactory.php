<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Catalog\PlanAuthoring;
use App\Models\Plan;

/**
 * Builds an {@see ArchivePlanAction} from a validated payload, re-loading the plan so a held
 * archival applies to the current plan row when approved.
 */
readonly class ArchivePlanActionFactory implements BuildsApprovableAction
{
    public function __construct(
        private PlanAuthoring $authoring,
        private RecordsAudit $audit,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::PlanArchive;
    }

    public function build(array $payload): ArchivePlanAction
    {
        $id = $payload['plan_id'] ?? null;
        $plan = Plan::query()->findOrFail(is_numeric($id) ? (int) $id : null);

        return new ArchivePlanAction($this->authoring, $this->audit, $plan);
    }
}
