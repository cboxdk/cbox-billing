<?php

declare(strict_types=1);

namespace App\Billing\Export\Enums;

use Carbon\CarbonImmutable;

/**
 * The kind of value a dataset's incremental cursor column carries, which decides how a stored
 * watermark is compared and serialized. An {@see Id} cursor is a strictly-monotonic
 * auto-increment key (the clean choice for the append-only event log); a {@see Timestamp}
 * cursor is a mutable-row `updated_at` (the choice for dimensional upserts).
 */
enum CursorKind: string
{
    case Id = 'id';
    case Timestamp = 'timestamp';

    /**
     * Coerce a raw database value from the cursor column into the comparable, storable string
     * a watermark records. Ids store their integer form; timestamps normalise to ISO-8601 UTC
     * so a stored watermark compares identically across drivers.
     */
    public function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Id => (string) (is_numeric($value) ? (int) $value : 0),
            self::Timestamp => CarbonImmutable::parse(is_scalar($value) ? (string) $value : 'now')
                ->utc()
                ->format('Y-m-d\TH:i:s.u\Z'),
        };
    }

    /**
     * Whether watermark `$a` is strictly greater than `$b` — used to keep only the true maximum
     * cursor seen across a streamed run.
     */
    public function greater(string $a, ?string $b): bool
    {
        if ($b === null) {
            return true;
        }

        return match ($this) {
            self::Id => (int) $a > (int) $b,
            self::Timestamp => strcmp($a, $b) > 0,
        };
    }
}
