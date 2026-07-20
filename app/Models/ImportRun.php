<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One operator import operation. It is created PLANNED (a dry-run resolved the uploaded export
 * against the ledger and surfaced conflicts + the proposed plan mapping — no writes), and later
 * COMMITTED (the same resolution executed through the real domain services). It is plane-scoped
 * (a run imports into exactly one plane), so a test-mode import never leaks into live.
 *
 * @property int $id
 * @property string $source
 * @property string $status
 * @property bool $dry_run
 * @property bool $livemode
 * @property string|null $actor_sub
 * @property string|null $actor_name
 * @property string|null $export_path
 * @property array<string, string>|null $plan_mapping
 * @property array<string, array<string, int>>|null $counts
 * @property list<array<string, mixed>>|null $conflicts
 * @property string|null $notes
 * @property Carbon|null $committed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImportRun extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'source', 'status', 'dry_run', 'actor_sub', 'actor_name',
        'export_path', 'plan_mapping', 'counts', 'conflicts', 'notes', 'committed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'plan_mapping' => 'array',
            'counts' => 'array',
            'conflicts' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    /** @return HasMany<ImportRunEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ImportRunEntry::class);
    }

    public function isCommitted(): bool
    {
        return $this->committed_at !== null;
    }
}
