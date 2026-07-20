<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Audit\Contracts\RedactsSubjectData;
use App\Models\Organization;

/**
 * Builds an {@see EraseSubjectDataAction} from a validated payload, re-loading the organization
 * so a held erasure applies to the current org row when the second operator approves it.
 */
readonly class EraseSubjectDataActionFactory implements BuildsApprovableAction
{
    public function __construct(private RedactsSubjectData $eraser) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::DataErase;
    }

    public function build(array $payload): EraseSubjectDataAction
    {
        $id = $payload['organization_id'] ?? null;
        $organization = Organization::query()->findOrFail(is_scalar($id) ? $id : null);

        return new EraseSubjectDataAction($this->eraser, $organization);
    }
}
