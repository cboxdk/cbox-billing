<?php

declare(strict_types=1);

namespace App\Billing\Approvals;

use App\Auth\CurrentUser;
use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalStatus;
use App\Billing\Approvals\Exceptions\ApprovalDenied;
use App\Billing\Approvals\ValueObjects\ApprovalActor;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The maker-checker decision engine. It owns the lifecycle of a held {@see ApprovalRequest}:
 * capturing it ({@see hold()}), recording a checker's decision ({@see approve()} / {@see reject()}),
 * running the held action exactly once when the approve-quorum is met, letting a maker withdraw
 * ({@see cancel()}), and lapsing stale requests ({@see expire()}).
 *
 * The three invariants that make this a real two-person rule:
 *
 *  1. TWO-PERSON RULE — a checker whose subject equals the maker's is refused; and one decision
 *     per checker (a DB UNIQUE backs it), so N approvals means N distinct people.
 *  2. EXACTLY-ONCE EXECUTION — execution reads the request under a row lock and no-ops if it is
 *     already executed, so a re-approve / double-submit can never run the money effect twice.
 *     The held action's own engine-level idempotency (a refund action id, a status guard) is the
 *     second line of defence.
 *  3. NOTHING RUNS WITHOUT QUORUM — a reject vetoes the whole request; a partial approval leaves
 *     it pending; only the quorum-reaching approval executes.
 *
 * Every transition is recorded on the tamper-evident audit trail.
 */
readonly class ApprovalService
{
    public function __construct(
        private ConnectionInterface $db,
        private ApprovableActionRegistry $registry,
        private ApprovalPolicy $policy,
        private RecordsAudit $audit,
        private CurrentUser $current,
    ) {}

    /** Capture a held action as a pending request (the maker side). Records `approval.requested`. */
    public function hold(ApprovableAction $action, ApprovalActor $requester, ?string $reason): ApprovalRequest
    {
        $context = $action->context();

        return $this->db->transaction(function () use ($action, $requester, $reason, $context): ApprovalRequest {
            $request = ApprovalRequest::query()->create([
                'action_type' => $action->type(),
                'payload' => $action->payload(),
                'requested_by_sub' => $requester->sub,
                'requested_by_name' => $requester->name,
                'reason' => $reason,
                'status' => ApprovalStatus::Pending,
                'organization_id' => $context->organizationId,
                'amount_minor' => $context->amountMinor,
                'currency' => $context->currency,
                'target_type' => $context->targetType,
                'target_id' => $context->targetId,
                'required_approvals' => $this->policy->requiredApprovals($action->type()),
                'expires_at' => $this->expiresAt(),
            ]);

            $this->audit->record(
                AuditAction::ApprovalRequested,
                $this->targetFor($request),
                sprintf('Submitted %s for approval: %s', $action->type()->label(), $action->describe()->summary),
                [
                    'approval_request_id' => $request->id,
                    'action_type' => $action->type()->value,
                    'amount_minor' => $context->amountMinor,
                    'required_approvals' => $request->required_approvals,
                    'reason' => $reason,
                ],
            );

            return $request;
        });
    }

    /**
     * Record an approval by a distinct checker. When it meets the quorum, the held action runs
     * exactly once and the request becomes `executed`; otherwise it stays `pending`.
     */
    public function approve(ApprovalRequest $request, ?string $note = null): ApprovalRequest
    {
        // Re-approving an already-executed request is a graceful no-op — the money effect
        // happened exactly once and must never be repeated.
        if ($request->status->isExecuted()) {
            return $request;
        }

        $checker = $this->checker();
        $this->guardDecision($request, $checker);

        return $this->db->transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $this->recordDecision($request, $checker, ApprovalDecision::APPROVE, $note);

            $this->audit->record(
                AuditAction::ApprovalApproved,
                $this->targetFor($request),
                sprintf('Approved %s (request #%d).', $request->action_type->label(), $request->id),
                ['approval_request_id' => $request->id, 'approver' => $checker->name, 'note' => $note],
            );

            $request->load('decisions');

            if ($request->hasReachedQuorum()) {
                return $this->execute($request, $checker, $note);
            }

            return $request->refresh();
        });
    }

    /** Reject the request — any checker may veto. Nothing executes. Records `approval.rejected`. */
    public function reject(ApprovalRequest $request, string $note): ApprovalRequest
    {
        $checker = $this->checker();
        $this->guardDecision($request, $checker);

        return $this->db->transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $this->recordDecision($request, $checker, ApprovalDecision::REJECT, $note);

            $request->forceFill([
                'status' => ApprovalStatus::Rejected,
                'approved_by_sub' => $checker->sub,
                'approved_by_name' => $checker->name,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record(
                AuditAction::ApprovalRejected,
                $this->targetFor($request),
                sprintf('Rejected %s (request #%d): %s', $request->action_type->label(), $request->id, $note),
                ['approval_request_id' => $request->id, 'approver' => $checker->name, 'reason' => $note],
            );

            return $request;
        });
    }

    /** The maker withdraws their own still-pending request. Records `approval.canceled`. */
    public function cancel(ApprovalRequest $request): ApprovalRequest
    {
        $actor = $this->checker();

        if (! $request->status->isPending() || ! $request->wasRequestedBy($actor->sub)) {
            throw ApprovalDenied::notCancelable();
        }

        return $this->db->transaction(function () use ($request, $actor): ApprovalRequest {
            $request->forceFill([
                'status' => ApprovalStatus::Canceled,
                'decided_at' => Carbon::now(),
            ])->save();

            $this->audit->record(
                AuditAction::ApprovalCanceled,
                $this->targetFor($request),
                sprintf('Canceled %s (request #%d).', $request->action_type->label(), $request->id),
                ['approval_request_id' => $request->id, 'actor' => $actor->name],
            );

            return $request;
        });
    }

    /**
     * Lapse every pending request past its expiry (a scheduled sweep). Returns the count
     * expired. Each records `approval.expired`; the held action never runs.
     */
    public function expire(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $due = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($due as $request) {
            $this->db->transaction(function () use ($request, $now): void {
                $request->forceFill(['status' => ApprovalStatus::Expired, 'decided_at' => $now])->save();

                $this->audit->record(
                    AuditAction::ApprovalExpired,
                    $this->targetFor($request),
                    sprintf('Expired %s (request #%d) — no decision before %s.', $request->action_type->label(), $request->id, $now->toDateString()),
                    ['approval_request_id' => $request->id],
                );
            });
        }

        return $due->count();
    }

    /**
     * Run the held action exactly once, under a row lock. Idempotent: an already-executed
     * request is a no-op that returns the prior result. Records `approval.executed`.
     */
    private function execute(ApprovalRequest $request, ApprovalActor $checker, ?string $note): ApprovalRequest
    {
        return $this->db->transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            // Idempotent re-approve / double-submit: the money effect already happened.
            if ($locked->status->isExecuted()) {
                return $locked;
            }

            $action = $this->registry->build($locked->action_type, $locked->payload);
            $action->validate();
            $outcome = $action->execute();

            $locked->forceFill([
                'status' => ApprovalStatus::Executed,
                'approved_by_sub' => $checker->sub,
                'approved_by_name' => $checker->name,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
                'executed_at' => Carbon::now(),
                'result' => $outcome->toArray(),
            ])->save();

            $this->audit->record(
                AuditAction::ApprovalExecuted,
                $this->targetFor($locked),
                sprintf('Executed %s (request #%d): %s', $locked->action_type->label(), $locked->id, $outcome->summary),
                ['approval_request_id' => $locked->id] + $outcome->data,
            );

            return $locked;
        });
    }

    /** Enforce the two-person rule and one-decision-per-checker before a decision is recorded. */
    private function guardDecision(ApprovalRequest $request, ApprovalActor $checker): void
    {
        if (! $request->status->isPending()) {
            throw ApprovalDenied::notPending();
        }

        if ($request->wasRequestedBy($checker->sub)) {
            throw ApprovalDenied::selfApproval();
        }

        if ($request->decisions()->where('approver_sub', $checker->sub)->exists()) {
            throw ApprovalDenied::alreadyDecided();
        }
    }

    private function recordDecision(ApprovalRequest $request, ApprovalActor $checker, string $decision, ?string $note): void
    {
        ApprovalDecision::query()->create([
            'approval_request_id' => $request->id,
            'approver_sub' => $checker->sub,
            'approver_name' => $checker->name,
            'decision' => $decision,
            'note' => $note,
            'decided_at' => Carbon::now(),
        ]);
    }

    /** The signed-in operator acting as the checker; falls back to the `system` sentinel. */
    private function checker(): ApprovalActor
    {
        return ApprovalActor::fromUser($this->current->user()) ?? new ApprovalActor('system');
    }

    /** Audit target: the underlying resource when known, else the request row itself. */
    private function targetFor(ApprovalRequest $request): AuditTarget
    {
        if ($request->target_type !== null && $request->target_id !== null) {
            return AuditTarget::of($request->target_type, $request->target_id, $request->organization_id);
        }

        return AuditTarget::of('approval_request', (string) $request->id, $request->organization_id);
    }

    private function expiresAt(): ?Carbon
    {
        $days = $this->policy->expireAfterDays();

        return $days !== null && $days > 0 ? Carbon::now()->addDays($days) : null;
    }
}
