<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A state a selling entity has PHYSICAL presence in (office, employees, inventory) —
 * a nexus trigger on its own — with an optional effective window. Console-authored:
 * the app declares where the seller has nexus, never the rate numbers.
 *
 * @property int $id
 * @property string $environment
 * @property string $seller_entity_id
 * @property string $subdivision
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerPhysicalPresence extends Model
{
    use BelongsToEnvironment;

    protected $table = 'seller_physical_presence';

    protected $fillable = ['seller_entity_id', 'subdivision', 'effective_from', 'effective_to'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    /**
     * Presence in effect on the given day: the window has started (or has no start)
     * and has not ended (or never ends) — both endpoints inclusive. Normalized to the
     * start of the day so the DATE-typed (midnight) `effective_to` is compared against a
     * midnight bound: a presence ending TODAY is still active today, not dropped a day early.
     *
     * @param  Builder<SellerPhysicalPresence>  $query
     */
    public function scopeActiveOn(Builder $query, Carbon $day): void
    {
        $onDay = $day->copy()->startOfDay();

        $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $onDay))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $onDay));
    }

    /** @return BelongsTo<SellerEntity, $this> */
    public function sellerEntity(): BelongsTo
    {
        return $this->belongsTo(SellerEntity::class);
    }
}
