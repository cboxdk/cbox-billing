<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\Enums\ApprovalStatus;
use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A held sensitive action awaiting a second-person decision (maker-checker). The row captures
 * the typed action + its serialized {@see $payload} (everything needed to reconstruct and run
 * it), the maker, the money at stake (for the threshold/queue), and the decision + execution
 * stamps. The action does NOT take effect until {@see $status} reaches `executed`.
 *
 * @property int $id
 * @property ApprovalActionType $action_type
 * @property array<string, mixed> $payload
 * @property string $requested_by_sub
 * @property string|null $requested_by_name
 * @property string|null $reason
 * @property ApprovalStatus $status
 * @property string|null $organization_id
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property string|null $target_type
 * @property string|null $target_id
 * @property int $required_approvals
 * @property string|null $approved_by_sub
 * @property string|null $approved_by_name
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $executed_at
 * @property array<string, mixed>|null $result
 * @property Carbon|null $expires_at
 * @property bool $livemode
 * @property-read Collection<int, ApprovalDecision> $decisions
 */
class ApprovalRequest extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'action_type', 'payload', 'requested_by_sub', 'requested_by_name', 'reason',
        'status', 'organization_id', 'amount_minor', 'currency', 'target_type', 'target_id',
        'required_approvals', 'approved_by_sub', 'approved_by_name', 'decided_at',
        'decision_note', 'executed_at', 'result', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action_type' => ApprovalActionType::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'amount_minor' => 'integer',
            'required_approvals' => 'integer',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** The distinct checkers who have voted to approve so far. */
    public function approvalCount(): int
    {
        return $this->decisions
            ->where('decision', ApprovalDecision::APPROVE)
            ->pluck('approver_sub')
            ->unique()
            ->count();
    }

    /** Whether the approve-quorum has been met (used the moment a new approval lands). */
    public function hasReachedQuorum(): bool
    {
        return $this->approvalCount() >= $this->required_approvals;
    }

    /** Whether the given operator subject is the maker (the two-person rule comparison). */
    public function wasRequestedBy(string $sub): bool
    {
        return $this->requested_by_sub === $sub;
    }
}
