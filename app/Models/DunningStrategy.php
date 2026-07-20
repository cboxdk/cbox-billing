<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use App\Billing\Payments\AdaptiveRetryStrategy;
use App\Billing\Payments\Dunning\RetryPlan;
use Illuminate\Database\Eloquent\Model;

/**
 * A durable per-decline-category strategy override for adaptive dunning (the console editor's
 * write target). A category without a row inherits the shipped config defaults — this row only
 * carries the knobs an operator has tuned away from them. Read back into a
 * {@see RetryPlan} by {@see AdaptiveRetryStrategy}.
 *
 * @property int $id
 * @property string $category
 * @property bool $retry
 * @property list<int> $backoff_days
 * @property int|null $max_attempts
 * @property bool $avoid_weekends
 * @property bool $align_to_payday
 */
class DunningStrategy extends Model
{
    use BelongsToEnvironment;

    protected $fillable = [
        'category', 'retry', 'backoff_days', 'max_attempts', 'avoid_weekends', 'align_to_payday',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retry' => 'boolean',
            'backoff_days' => 'array',
            'max_attempts' => 'integer',
            'avoid_weekends' => 'boolean',
            'align_to_payday' => 'boolean',
        ];
    }
}
