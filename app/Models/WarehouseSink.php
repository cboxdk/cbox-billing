<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\Enums\Warehouse;
use App\Billing\Export\ValueObjects\WarehouseTarget;
use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configured warehouse destination: which object-store disk/prefix a dataset partition is
 * staged to, which format, which datasets it delivers, and the load-side coordinates the manifest
 * generators phrase their `COPY`/`bq load`/DDL against.
 *
 * A sink is operator INFRASTRUCTURE of one billing ENVIRONMENT (via {@see BelongsToEnvironment}),
 * so a sink configured while switched to a sandbox belongs to that plane: it is invisible in
 * production and is torn down when the sandbox is destroyed (it survives a reset, as config). The
 * `environment` it lives in is stamped from the ambient plane, never mass-assignable; the separate
 * `livemode` field is the operator's choice of WHICH plane's data the export partitions on (the
 * external warehouse data-contract), independent of where the sink config lives.
 */
class WarehouseSink extends Model
{
    use BelongsToEnvironment;

    protected $fillable = [
        'key', 'name', 'warehouse', 'disk', 'prefix', 'format', 'livemode',
        'datasets', 'schedule', 'external_base', 'target_schema', 'target_stage',
        'credential', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'enabled' => 'boolean',
            'datasets' => 'array',
        ];
    }

    /** @return HasMany<WarehouseSyncCursor, $this> */
    public function cursors(): HasMany
    {
        return $this->hasMany(WarehouseSyncCursor::class, 'sink_id');
    }

    /** @return HasMany<WarehouseSyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WarehouseSyncRun::class, 'sink_id');
    }

    public function warehouseEnum(): Warehouse
    {
        return Warehouse::parse($this->warehouse);
    }

    public function formatEnum(): ExportFormat
    {
        return ExportFormat::parse($this->format);
    }

    /**
     * The dataset keys this sink delivers. Read through the raw attribute so the JSON payload is
     * normalised whether the driver hands it back decoded (the array cast) or as a string.
     *
     * @return list<string>
     */
    public function datasetKeys(): array
    {
        $raw = $this->getAttribute('datasets');

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /** The disk-relative prefix under which this sink stages files (no leading/trailing slash). */
    public function normalizedPrefix(): string
    {
        return trim($this->prefix, '/');
    }

    /**
     * The load-side coordinates for the manifest generators. Unset fields become explicit,
     * bracketed placeholders (never fabricated), so a copy-pasted manifest tells the operator
     * exactly what to fill in.
     */
    public function target(): WarehouseTarget
    {
        $base = is_string($this->external_base) && $this->external_base !== ''
            ? $this->external_base
            : '<external-base-uri e.g. s3://your-bucket/'.$this->normalizedPrefix().'>';

        $schema = is_string($this->target_schema) && $this->target_schema !== ''
            ? $this->target_schema
            : 'analytics_billing';

        $stage = is_string($this->target_stage) && $this->target_stage !== '' ? $this->target_stage : null;
        $credential = is_string($this->credential) && $this->credential !== '' ? $this->credential : null;

        return new WarehouseTarget($base, $schema, $stage, $credential);
    }
}
