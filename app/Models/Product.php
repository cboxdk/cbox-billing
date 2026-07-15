<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable product. Groups one or more {@see Plan} price points; the plan carries
 * the money, credit grants and metered entitlements.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 */
class Product extends Model
{
    protected $fillable = ['key', 'name', 'description'];

    /** @return HasMany<Plan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
