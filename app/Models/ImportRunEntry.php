<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a run's import log: a single source record, what the importer did with it
 * (created / updated / skipped / failed / conflict), and the app model it resolved to — the
 * browsable source→app id mapping. Plane is carried explicitly (set from the owning run) rather
 * than through the mode scope, so an old run's log renders regardless of the console's current
 * plane.
 *
 * @property int $id
 * @property int $import_run_id
 * @property string $source_type
 * @property string $source_id
 * @property string $outcome
 * @property string|null $app_type
 * @property string|null $app_id
 * @property string|null $message
 * @property bool $livemode
 */
class ImportRunEntry extends Model
{
    protected $fillable = [
        'import_run_id', 'source_type', 'source_id', 'outcome',
        'app_type', 'app_id', 'message', 'livemode',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
        ];
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }
}
