<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Connection-backed {@see LicenseRevocationRegistry} — the durable replacement for the
 * engine's in-memory default, so revocations survive a restart and are shared across
 * issuer nodes. Revoking is idempotent: it upserts on the `license_id` primary key, so a
 * repeat revoke keeps the original `revoked_at` rather than duplicating the id.
 */
readonly class DatabaseRevocationRegistry implements LicenseRevocationRegistry
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function revoke(string $licenseId, ?string $reason = null): void
    {
        if ($this->isRevoked($licenseId)) {
            return;
        }

        $this->table()->insert([
            'license_id' => $licenseId,
            'revoked_at' => Carbon::now(),
            'reason' => $reason,
        ]);
    }

    public function isRevoked(string $licenseId): bool
    {
        return $this->table()->where('license_id', $licenseId)->exists();
    }

    /**
     * @return list<string>
     */
    public function revokedIds(): array
    {
        return array_values($this->table()
            ->orderBy('license_id')
            ->pluck('license_id')
            ->map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all());
    }

    private function table(): Builder
    {
        return $this->db->table('license_revocations');
    }
}
