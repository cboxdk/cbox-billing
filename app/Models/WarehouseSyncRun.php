<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the delivery/run log: a single staged partition for a (sink, dataset) with its
 * row/byte counts, the cursor window it covered, the staged file path, the load-manifest path,
 * and the outcome. This is the audit trail an operator reviews to confirm a sink is delivering.
 */
class WarehouseSyncRun extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sink_id', 'dataset', 'warehouse', 'format', 'sync_mode', 'status',
        'partition_path', 'manifest_path', 'rows', 'bytes',
        'cursor_from', 'cursor_to', 'partition_date', 'error',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'integer',
            'bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WarehouseSink, $this> */
    public function sink(): BelongsTo
    {
        return $this->belongsTo(WarehouseSink::class, 'sink_id');
    }
}
