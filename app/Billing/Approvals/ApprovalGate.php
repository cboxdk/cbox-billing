<?php

declare(strict_types=1);

namespace App\Billing\Approvals;

use App\Auth\CurrentUser;
use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\ValueObjects\ApprovalActor;
use App\Billing\Approvals\ValueObjects\ApprovalResult;
use App\Models\ApprovalRequest;

/**
 * The single choke point every sensitive mutation passes through. A controller assembles the
 * {@see ApprovableAction} from its validated request and hands it here; the gate asks the
 * {@see ApprovalPolicy} whether a second person is required:
 *
 *  - NOT required (policy disabled, or below the threshold): the action is validated and run
 *    immediately — byte-for-byte the pre-engine behaviour, so there is no regression.
 *  - required: the action is captured as a pending {@see ApprovalRequest} via the
 *    {@see ApprovalService} and does NOT take effect; the controller flashes "submitted for
 *    approval". The held action runs later, through the exact same {@see ApprovableAction::execute()},
 *    when a distinct checker approves it.
 *
 * This is what generalizes the CPQ deal-desk approval into a reusable rule for every money-
 * sensitive action, without each controller re-implementing thresholds or the two-person rule.
 */
readonly class ApprovalGate
{
    public function __construct(
        private ApprovalPolicy $policy,
        private ApprovalService $service,
        private CurrentUser $current,
    ) {}

    /**
     * Run the action now, or hold it for approval, per policy. Returns an {@see ApprovalResult}
     * the controller branches on to flash the right message.
     */
    public function run(ApprovableAction $action, ?string $reason = null): ApprovalResult
    {
        if (! $this->policy->requiresApproval($action)) {
            $action->validate();

            return ApprovalResult::executed($action->execute());
        }

        $requester = ApprovalActor::fromUser($this->current->user()) ?? new ApprovalActor('system');

        return ApprovalResult::held($this->service->hold($action, $requester, $reason));
    }
}
