<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Models\Organization;
use Cbox\Billing\Account\Contracts\AccountStanding;

/**
 * Builds a {@see SuspendCustomerAction} from a validated payload, re-loading the organization
 * so a held suspension applies to the current org row when approved.
 */
readonly class SuspendCustomerActionFactory implements BuildsApprovableAction
{
    public function __construct(
        private AccountStanding $standing,
        private RecordsAudit $audit,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::CustomerSuspend;
    }

    public function build(array $payload): SuspendCustomerAction
    {
        $id = $payload['organization_id'] ?? null;
        $organization = Organization::query()->findOrFail(is_scalar($id) ? $id : null);

        return new SuspendCustomerAction($this->standing, $this->audit, $organization);
    }
}
