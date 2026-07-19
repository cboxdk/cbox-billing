<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Fx\Enums\FxRateOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A persisted foreign-exchange reference rate: `1 base = rate quote`, effective on
 * `as_of_date`, from a named `source` ({@see FxRateOrigin}). Used only by consolidated
 * reporting; the ledger never touches it. `rate` is kept as an exact decimal string (Eloquent
 * returns the column value verbatim) so no minor-unit precision is lost — arithmetic goes
 * through Brick big numbers in the Fx repository, never a float cast.
 *
 * @property int $id
 * @property Carbon $as_of_date
 * @property string $base
 * @property string $quote
 * @property string $rate
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FxRate extends Model
{
    protected $fillable = [
        'as_of_date', 'base', 'quote', 'rate', 'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            // Keep the rate an exact decimal STRING (never a float) at the column's full scale,
            // so Brick big-number arithmetic in the Fx repository loses no precision.
            'rate' => 'decimal:12',
        ];
    }

    public function origin(): FxRateOrigin
    {
        return FxRateOrigin::from($this->source);
    }
}
