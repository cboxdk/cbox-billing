<?php

declare(strict_types=1);

namespace App\Billing\Environments;

use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Billing\Environments\ValueObjects\ProvisionedEnvironment;
use App\Billing\Mode\BillingMode;
use App\Models\ApiToken;
use App\Models\Environment;
use Illuminate\Database\ConnectionInterface;

/**
 * Provisions a new SANDBOX plane for CI / programmatic use. Without a source it creates a bare
 * sandbox (empty config + book); with a `$cloneFrom` it deep-copies that environment's config via
 * the {@see ClonesEnvironments} cloner (empty book, test gateway keys). When `$withToken` is set it
 * mints an OPERATOR API token BOUND to the new plane in the same transaction, so CI immediately has
 * a credential that resolves only this environment's config + data — the throwaway-env token the
 * end-to-end flow drives.
 *
 * All key validation (reserved/invalid/taken) is delegated to the cloner's guard, reused here for
 * the non-clone path too, so a create and a clone refuse the same bad keys identically.
 */
readonly class EnvironmentProvisioner implements CreatesEnvironments
{
    public function __construct(
        private ConnectionInterface $connection,
        private ClonesEnvironments $cloner,
    ) {}

    public function create(string $key, ?string $name = null, ?Environment $cloneFrom = null, bool $withToken = false): ProvisionedEnvironment
    {
        return $this->connection->transaction(function () use ($key, $name, $cloneFrom, $withToken): ProvisionedEnvironment {
            $cloned = $cloneFrom !== null;

            $environment = $cloned
                ? $this->cloner->clone($cloneFrom, $key, $name)
                : $this->createBare($key, $name);

            $plaintext = null;

            if ($withToken) {
                ['plaintext' => $plaintext] = ApiToken::issue(
                    name: sprintf('CI token · %s', $environment->key),
                    mode: BillingMode::Test,
                    environmentKey: $environment->key,
                );
            }

            return new ProvisionedEnvironment($environment, $plaintext, $cloned);
        });
    }

    /** Create a bare sandbox (no cloned config) after the cloner's key guard refuses a bad key. */
    private function createBare(string $key, ?string $name): Environment
    {
        $this->guardKey($key);

        return Environment::query()->create([
            'key' => $key,
            'name' => $name ?? ucfirst($key),
            'type' => EnvironmentType::Sandbox,
            'protected' => false,
            'gateway_key_mode' => GatewayKeyMode::Test,
        ]);
    }

    /**
     * Reuse the cloner's key discipline for the non-clone path: reserved production key, malformed
     * key, or an already-taken key are all refused before any write. The taken-check queries the
     * table directly (not the request-memoised registry) so a create can't be fooled by a stale
     * negative lookup.
     *
     * @throws EnvironmentCloneException
     */
    private function guardKey(string $key): void
    {
        if ($key === Environment::PRODUCTION) {
            throw EnvironmentCloneException::reservedKey($key);
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $key) !== 1) {
            throw EnvironmentCloneException::invalidKey($key);
        }

        if (Environment::query()->where('key', $key)->exists()) {
            throw EnvironmentCloneException::keyTaken($key);
        }
    }
}
