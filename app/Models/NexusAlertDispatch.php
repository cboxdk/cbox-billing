<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The idempotency record that an economic-nexus alert already fired for a (seller entity,
 * state, measurement period, status). The unique key on the table makes the emitter surface
 * each crossing exactly once per period; this model is the environment-scoped accessor the
 * sweep and the console watchlist read.
 *
 * @property int $id
 * @property string $environment
 * @property string $seller_entity_id
 * @property string $subdivision
 * @property string $period_key
 * @property string $status
 * @property Carbon|null $created_at
 */
class NexusAlertDispatch extends Model
{
    use BelongsToEnvironment;

    public const UPDATED_AT = null;

    protected $fillable = ['seller_entity_id', 'subdivision', 'period_key', 'status'];
}
