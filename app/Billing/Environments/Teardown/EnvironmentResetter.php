<?php

declare(strict_types=1);

namespace App\Billing\Environments\Teardown;

use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\Contracts\ResetsEnvironments;
use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Environments\ValueObjects\EnvironmentTeardownResult;
use App\Models\Environment;
use Illuminate\Database\ConnectionInterface;

/**
 * Resets a sandbox: wipes the plane's transactional/tenant data (the runtime book) while keeping
 * its config, all in one transaction. With `$reseedFrom` it goes further — wipes the plane's config
 * too and re-copies it from the given source environment (the "reset to a fresh clone of production"
 * flow). Production is refused (deny-by-default): its book is the real business, never disposable.
 */
readonly class EnvironmentResetter implements ResetsEnvironments
{
    public function __construct(
        private ConnectionInterface $connection,
        private EnvironmentDataEraser $eraser,
        private ClonesEnvironments $cloner,
        private EnvironmentGatewayStore $gateways,
    ) {}

    public function reset(Environment $environment, ?Environment $reseedFrom = null): EnvironmentTeardownResult
    {
        if ($environment->protected) {
            throw EnvironmentProtectedException::cannotReset($environment);
        }

        return $this->connection->transaction(function () use ($environment, $reseedFrom): EnvironmentTeardownResult {
            if ($reseedFrom !== null) {
                // Reseed: wipe EVERYTHING (config + book), then re-copy config from the source.
                $deleted = $this->eraser->wipeAll($environment->key);
                $this->cloner->copyConfigInto($reseedFrom, $environment);
            } else {
                // Plain reset: wipe only the transactional book; the plane's config survives.
                $deleted = $this->eraser->wipeTransactional($environment->key);
            }

            $this->gateways->forget($environment->key);

            return new EnvironmentTeardownResult($environment->key, environmentRemoved: false, deletedByTable: $deleted);
        });
    }
}
