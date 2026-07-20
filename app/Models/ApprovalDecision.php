<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One checker's decision on a held {@see ApprovalRequest} — an approve or a reject, stamped
 * with the operator subject and an optional note. A UNIQUE(request, approver) constraint keeps
 * it to one decision per checker, which is how the distinct-approver quorum is counted and how
 * the two-person rule is backstopped at the database.
 *
 * @property int $id
 * @property int $approval_request_id
 * @property string $approver_sub
 * @property string|null $approver_name
 * @property string $decision
 * @property string|null $note
 * @property Carbon $decided_at
 */
class ApprovalDecision extends Model
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    protected $fillable = [
        'approval_request_id', 'approver_sub', 'approver_name', 'decision', 'note', 'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
