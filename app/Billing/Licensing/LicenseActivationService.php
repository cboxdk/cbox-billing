<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Licensing\ValueObjects\ActivationBundle;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\RevocationPublisher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Serves the optional online activation heartbeat. Given a deployment id, it returns the
 * latest signed license bound to that deployment (so a deployment that was reissued picks
 * up the new artifact) alongside the freshly-cut, signed revocation list and the issuer
 * public key.
 *
 * This is the refresh path only. An offline install verifies the license it was handed
 * against the bundled public key with no network — nothing here is on that critical path,
 * and a deployment with no license on file resolves to `null` (a generic not-found to the
 * caller), never a fabricated bundle.
 */
readonly class LicenseActivationService
{
    public function __construct(
        private IssuedLicenseStore $store,
        private RevocationPublisher $publisher,
        private Config $config,
        private BillingContext $context,
        private ConnectionInterface $db,
    ) {}

    public function refresh(string $deploymentId): ?ActivationBundle
    {
        // HP1: the activation heartbeat carries no credential to set the plane, so resolve the
        // deployment's OWNING plane UNSCOPED first (the issued-license store scopes to the ambient
        // — LIVE by default — plane, so a test deployment would otherwise 404), then run the whole
        // refresh in that plane so the license lookup AND the revocation list are cut per plane.
        return $this->context->runInMode($this->planeFor($deploymentId), fn (): ?ActivationBundle => $this->refreshInPlane($deploymentId));
    }

    private function refreshInPlane(string $deploymentId): ?ActivationBundle
    {
        $license = $this->store->forDeployment($deploymentId);

        if ($license === null) {
            return null;
        }

        $publicKey = $this->config->get('billing.licensing.public_key');

        return new ActivationBundle(
            deploymentId: $license->deploymentId,
            licenseId: $license->id,
            licenseKey: $license->key,
            expiresAt: $license->expiresAt,
            revocationList: $this->publisher->currentList(Carbon::now()->toDateTimeImmutable()),
            publicKey: is_string($publicKey) ? $publicKey : '',
        );
    }

    /**
     * The plane the deployment's current license lives in, resolved WITHOUT the plane scope. The
     * newest (longest-valid) issued license for the deployment names the plane; an unknown
     * deployment falls back to the ambient plane (the lookup will simply find nothing there too).
     */
    private function planeFor(string $deploymentId): BillingMode
    {
        $livemode = $this->db->table('issued_licenses')
            ->where('deployment_id', $deploymentId)
            ->orderByDesc('expires_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->value('livemode');

        return $livemode === null
            ? $this->context->mode()
            : BillingMode::fromLivemode((bool) $livemode);
    }
}
