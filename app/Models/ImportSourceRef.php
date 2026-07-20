<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Model;

/**
 * The idempotency + provenance ledger: a stable mapping from a provider record
 * (`source`, `source_type`, `source_id`) to the app record it became (`app_type`, `app_id`),
 * unique per plane. A re-run of the same export matches here and updates/skips rather than
 * duplicating; the mapping is also the durable record of where every migrated row came from.
 *
 * @property int $id
 * @property string $source
 * @property string $source_type
 * @property string $source_id
 * @property string $app_type
 * @property string $app_id
 * @property int|null $import_run_id
 * @property bool $livemode
 */
class ImportSourceRef extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'source', 'source_type', 'source_id', 'app_type', 'app_id', 'import_run_id',
    ];
}
