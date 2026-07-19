<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Payments\PaymentRetryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event in a smart-retry's append-only attempts timeline (see the migration). Written by
 * {@see PaymentRetryService} at every step; read by the console detail
 * panel and the recovery analytics. Never mutated after insert.
 *
 * @property int $id
 * @property int $payment_retry_id
 * @property int $attempt
 * @property string $outcome
 * @property string|null $decline_code
 * @property string|null $decline_category
 * @property string|null $gateway_reference
 * @property string|null $detail
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $created_at
 */
class PaymentRetryAttempt extends Model
{
    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_SCHEDULED = 'scheduled';

    public const OUTCOME_RECOVERED = 'recovered';

    public const OUTCOME_EXHAUSTED = 'exhausted';

    public const OUTCOME_AUTHENTICATE = 'authenticate';

    public const OUTCOME_CARD_UPDATED = 'card_updated';

    public const OUTCOME_STOPPED = 'stopped';

    protected $fillable = [
        'payment_retry_id', 'attempt', 'outcome',
        'decline_code', 'decline_category', 'gateway_reference', 'detail', 'next_attempt_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentRetry, $this> */
    public function paymentRetry(): BelongsTo
    {
        return $this->belongsTo(PaymentRetry::class);
    }
}
