<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sellable product. Groups one or more {@see Plan} price points; the plan carries
 * the money, credit grants and metered entitlements.
 *
 * A product that still groups plans is archived (`archived_at`) rather than hard-deleted,
 * so the plans its subscribers grandfather onto — and the catalog the engine resolves —
 * are never orphaned; only a product with zero plans is ever removed outright.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $archived_at
 */
class Product extends Model
{
    use BelongsToEnvironment;

    protected $fillable = ['key', 'name', 'description', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** Whether the product has been archived (soft-deactivated). */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Only live (non-archived) products.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return HasMany<Plan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
