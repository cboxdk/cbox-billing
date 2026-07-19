<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The durable smart-retry state for one invoice's failed renewal charge. The row is both
 * the schedule and the idempotency key: `attempts` counts fired retries against
 * `max_attempts`, and `next_attempt_at` gates the next one — advanced (or nulled on a
 * terminal outcome) the instant an attempt completes, so an attempt slot can only fire
 * once.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $organization_id
 * @property int|null $subscription_id
 * @property int $attempts
 * @property int $max_attempts
 * @property string $status
 * @property Carbon $first_failed_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $last_attempt_at
 * @property string|null $last_reference
 */
class PaymentRetry extends Model
{
    public const STATUS_RETRYING = 'retrying';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_EXHAUSTED = 'exhausted';

    /** Manually halted by an operator before the schedule ran out (Wave 3). */
    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'invoice_id', 'organization_id', 'subscription_id',
        'attempts', 'max_attempts', 'status',
        'first_failed_at', 'next_attempt_at', 'last_attempt_at', 'last_reference',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'first_failed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** Still working through the retry schedule (neither recovered nor exhausted). */
    public function isRetrying(): bool
    {
        return $this->status === self::STATUS_RETRYING;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
