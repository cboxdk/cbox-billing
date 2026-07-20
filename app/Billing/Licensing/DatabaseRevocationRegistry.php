<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Webhooks\Events\LicenseRevoked as LicenseRevokedEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Connection-backed {@see LicenseRevocationRegistry} — the durable replacement for the
 * engine's in-memory default, so revocations survive a restart and are shared across
 * issuer nodes. Revoking is idempotent: it upserts on the `license_id` primary key, so a
 * repeat revoke keeps the original `revoked_at` rather than duplicating the id.
 *
 * Plane-aware: a revocation carries the request's `environment` (with `livemode` as its mirror)
 * and every read is confined to the current plane, so a sandbox revocation never appears on the
 * production signed revocation list (and a production revocation never leaks into a sandbox
 * deployment's activation refresh) — the published list is cut per plane.
 */
readonly class DatabaseRevocationRegistry implements LicenseRevocationRegistry
{
    public function __construct(
        private ConnectionInterface $db,
        private BillingContext $context,
    ) {}

    public function revoke(string $licenseId, ?string $reason = null): void
    {
        if ($this->isRevoked($licenseId)) {
            return;
        }

        $this->table()->insert([
            'license_id' => $licenseId,
            'environment' => $this->context->environmentKey(),
            'livemode' => $this->context->livemode(),
            'revoked_at' => Carbon::now(),
            'reason' => $reason,
        ]);

        // First revoke only (the early return above makes this non-idempotent-safe): fan out
        // `license.revoked`.
        event(new LicenseRevokedEvent($licenseId, $reason));
    }

    public function isRevoked(string $licenseId): bool
    {
        return $this->scoped()->where('license_id', $licenseId)->exists();
    }

    /**
     * @return list<string>
     */
    public function revokedIds(): array
    {
        return array_values($this->scoped()
            ->orderBy('license_id')
            ->pluck('license_id')
            ->map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all());
    }

    private function table(): Builder
    {
        return $this->db->table('license_revocations');
    }

    /** The revocation table constrained to the current plane. */
    private function scoped(): Builder
    {
        return $this->table()->where('environment', $this->context->environmentKey());
    }
}
