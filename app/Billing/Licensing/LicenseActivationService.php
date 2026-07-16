<?php

declare(strict_types=1);

namespace App\Billing\Licensing;

use App\Billing\Licensing\ValueObjects\ActivationBundle;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\RevocationPublisher;
use Illuminate\Contracts\Config\Repository as Config;
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
    ) {}

    public function refresh(string $deploymentId): ?ActivationBundle
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
}
