<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The incremental watermark for one (sink, dataset): the last cursor value delivered, so the
 * next scheduled sync stages only rows strictly past it. Snapshot datasets keep no meaningful
 * watermark and simply full-refresh each run.
 */
class WarehouseSyncCursor extends Model
{
    protected $fillable = [
        'sink_id', 'dataset', 'cursor_kind', 'cursor_value', 'rows_total', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WarehouseSink, $this> */
    public function sink(): BelongsTo
    {
        return $this->belongsTo(WarehouseSink::class, 'sink_id');
    }

    public function value(): ?string
    {
        return is_string($this->cursor_value) ? $this->cursor_value : null;
    }
}
