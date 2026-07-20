<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A controlled A/B pricing experiment run on a public {@see PricingTable} (`pricing_table_id` —
 * the table whose `/pricing/{key}` page the experiment intercepts). It carries the hypothesis,
 * the {@see ExperimentStatus} lifecycle, the {@see ExperimentMetric} it optimises for, and its
 * {@see ExperimentVariant}s (each with a traffic weight and the pricing table it serves).
 *
 * When `running`, a visitor of `/pricing/{key}` is stickily assigned to a variant (a hashed,
 * deterministic bucket — see {@see App\Billing\Experiments\VariantAssigner}) and served that
 * variant's table; an impression is recorded once per visitor per variant. Concluding can name
 * a `promoted_variant_id` winner, after which the base page serves that winning variant's table
 * permanently (no more assignment). The experiment owns no money — its variants point at real,
 * catalog-backed pricing tables.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $hypothesis
 * @property ExperimentStatus $status
 * @property ExperimentMetric $primary_metric
 * @property int $pricing_table_id
 * @property int|null $promoted_variant_id
 * @property Carbon|null $started_at
 * @property Carbon|null $concluded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Experiment extends Model
{
    protected $fillable = [
        'key', 'name', 'hypothesis', 'status', 'primary_metric', 'pricing_table_id',
        'promoted_variant_id', 'started_at', 'concluded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExperimentStatus::class,
            'primary_metric' => ExperimentMetric::class,
            'started_at' => 'datetime',
            'concluded_at' => 'datetime',
        ];
    }

    /**
     * Running experiments only (the ones that assign variants + accrue impressions).
     *
     * @param  Builder<Experiment>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->where('status', ExperimentStatus::Running->value);
    }

    /** The public pricing table this experiment runs on (its `/pricing/{key}` is intercepted). */
    /** @return BelongsTo<PricingTable, $this> */
    public function pricingTable(): BelongsTo
    {
        return $this->belongsTo(PricingTable::class);
    }

    /** The ordered variants, control first. */
    /** @return HasMany<ExperimentVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ExperimentVariant::class)->orderByDesc('is_control')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ExperimentImpression, $this> */
    public function impressions(): HasMany
    {
        return $this->hasMany(ExperimentImpression::class);
    }

    /** @return HasMany<ExperimentConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(ExperimentConversion::class);
    }

    /** The promoted winning variant, if the experiment was concluded with one. */
    /** @return BelongsTo<ExperimentVariant, $this> */
    public function promotedVariant(): BelongsTo
    {
        return $this->belongsTo(ExperimentVariant::class, 'promoted_variant_id');
    }

    /** The required control variant of the experiment (the baseline to measure lift against). */
    public function control(): ?ExperimentVariant
    {
        return $this->variants->firstWhere('is_control', true);
    }
}
