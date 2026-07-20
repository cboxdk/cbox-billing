<?php

declare(strict_types=1);

namespace App\Billing\Approvals;

use App\Billing\Approvals\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The read side of the approval console: the pending checker queue, a maker's own requests, and
 * the pending count for the nav badge. Every query is livemode-scoped through the model, so the
 * queue a checker sees is confined to the plane they are operating in.
 */
readonly class ApprovalQueue
{
    /**
     * Requests awaiting a decision (the checker queue), oldest first.
     *
     * @return LengthAwarePaginator<int, ApprovalRequest>
     */
    public function pending(int $perPage = 25): LengthAwarePaginator
    {
        return ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->with('decisions')
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    /**
     * A maker's own requests across every status, newest first.
     *
     * @return LengthAwarePaginator<int, ApprovalRequest>
     */
    public function forRequester(string $sub, int $perPage = 25): LengthAwarePaginator
    {
        return ApprovalRequest::query()
            ->where('requested_by_sub', $sub)
            ->with('decisions')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /** How many requests are pending in the current plane — the nav badge count. */
    public function pendingCount(): int
    {
        return ApprovalRequest::query()->where('status', ApprovalStatus::Pending->value)->count();
    }
}
