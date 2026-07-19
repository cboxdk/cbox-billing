<?php

declare(strict_types=1);

namespace App\Billing\Fx\Enums;

/**
 * Where an FX rate came from — the provenance shown next to every converted figure so a
 * consolidated number is auditable, never a black box.
 *
 *  - {@see Ecb}      — the European Central Bank euro reference-rate feed (base EUR).
 *  - {@see Override} — an operator/treasury-supplied rate (config or console), which
 *    supersedes ECB on the same (date, pair).
 *  - {@see Derived}  — a cross-rate computed at read time from ECB/override legs via the EUR
 *    pivot (or an inverse of a stored leg); no such row is persisted, so the provenance is
 *    marked explicitly rather than implied.
 */
enum FxRateOrigin: string
{
    case Ecb = 'ecb';
    case Override = 'override';
    case Derived = 'derived';

    /** The human label shown in the console rates view. */
    public function label(): string
    {
        return match ($this) {
            self::Ecb => 'ECB',
            self::Override => 'Override',
            self::Derived => 'Derived',
        };
    }
}
