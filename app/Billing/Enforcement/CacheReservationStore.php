<?php

declare(strict_types=1);

namespace App\Billing\Enforcement;

use App\Billing\Enforcement\Contracts\ReservationStore;
use Cbox\Billing\Metering\ValueObjects\ReservationSet;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A cache-backed {@see ReservationStore}. The reserved set — an immutable value object —
 * is serialized under its reservation id with a TTL, so an abandoned reservation is
 * reclaimed automatically when the window lapses. In production this rides the app's
 * shared cache (Redis); in tests the array store keeps it for the request pair.
 */
readonly class CacheReservationStore implements ReservationStore
{
    private const PREFIX = 'cbox-billing:reservation:';

    public function __construct(private Cache $cache) {}

    public function put(ReservationSet $set, int $ttlSeconds): void
    {
        $this->cache->put(self::PREFIX.$set->id, serialize($set), $ttlSeconds);
    }

    public function get(string $reservationId): ?ReservationSet
    {
        $payload = $this->cache->get(self::PREFIX.$reservationId);

        if (! is_string($payload)) {
            return null;
        }

        $set = unserialize($payload, ['allowed_classes' => true]);

        return $set instanceof ReservationSet ? $set : null;
    }

    public function forget(string $reservationId): void
    {
        $this->cache->forget(self::PREFIX.$reservationId);
    }
}
