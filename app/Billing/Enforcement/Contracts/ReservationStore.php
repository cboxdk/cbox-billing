<?php

declare(strict_types=1);

namespace App\Billing\Enforcement\Contracts;

use Cbox\Billing\Metering\ValueObjects\ReservationSet;

/**
 * Persists a held {@see ReservationSet} between the `/reserve` call that created it and
 * the `/commit` (or release) that settles it — the two arrive as separate HTTP requests,
 * so the reserved slices (with their claimed positions and policies) must survive the
 * gap. Keyed on the reservation id the reserve call returned.
 */
interface ReservationStore
{
    public function put(ReservationSet $set, int $ttlSeconds): void;

    public function get(string $reservationId): ?ReservationSet;

    public function forget(string $reservationId): void;
}
