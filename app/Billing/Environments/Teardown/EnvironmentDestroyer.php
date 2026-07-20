<?php

declare(strict_types=1);

namespace App\Billing\Environments\Teardown;

use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Environments\ValueObjects\EnvironmentTeardownResult;
use App\Models\ApiToken;
use App\Models\Environment;
use Illuminate\Database\ConnectionInterface;

/**
 * Hard teardown of a sandbox for CI: deletes the environment row AND everything scoped to the
 * plane — all config + transactional rows, its gateway credentials, and every API token bound to
 * it — in one transaction, so nothing of the plane survives. Production is refused
 * (deny-by-default): the protected plane can never be destroyed.
 *
 * A token bound to the destroyed plane is removed with it, so a leaked CI token can never
 * authenticate again after teardown.
 */
readonly class EnvironmentDestroyer implements DestroysEnvironments
{
    public function __construct(
        private ConnectionInterface $connection,
        private EnvironmentDataEraser $eraser,
        private EnvironmentGatewayStore $gateways,
    ) {}

    public function destroy(Environment $environment): EnvironmentTeardownResult
    {
        if ($environment->protected) {
            throw EnvironmentProtectedException::cannotDestroy($environment);
        }

        return $this->connection->transaction(function () use ($environment): EnvironmentTeardownResult {
            // Every plane-scoped row: config (catalog/branding/gateways/…) + the transactional book.
            $deleted = $this->eraser->wipeAll($environment->key);

            // API tokens bound to the plane die with it — a leaked CI token can't outlive teardown.
            // Deleted via the base query builder so the count is a plain int for the typed result.
            $tokens = $this->connection->table((new ApiToken)->getTable())
                ->where('environment', $environment->key)
                ->delete();
            if ($tokens > 0) {
                $deleted['api_tokens'] = $tokens;
            }

            // Finally the registry row itself.
            $environment->delete();
            $this->gateways->forget($environment->key);

            return new EnvironmentTeardownResult($environment->key, environmentRemoved: true, deletedByTable: $deleted);
        });
    }
}
