<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Environments\Contracts\ResetsEnvironments;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use Illuminate\Console\Command;

/**
 * Reset a SANDBOX environment: wipe its transactional/tenant data (the runtime book — subscriptions,
 * invoices, customers, ledger/wallet, dunning, redemptions, licenses, webhook deliveries, seats, …)
 * while keeping its config. With `--reseed-from` it also wipes the plane's config and re-copies it
 * from the given source environment (reset to a fresh clone). Thin adapter over the
 * {@see ResetsEnvironments} contract; production is refused by the service (never wiped).
 *
 *   php artisan environment:reset acme-test
 *   php artisan environment:reset acme-test --reseed-from=production
 */
class ResetEnvironment extends Command
{
    protected $signature = 'environment:reset
        {key : The sandbox environment to reset}
        {--reseed-from= : Also wipe this plane\'s config and re-copy it from this source environment}';

    protected $description = 'Reset a sandbox: wipe its transactional data (optionally re-cloning config), keeping production untouched.';

    public function handle(EnvironmentRegistry $registry, ResetsEnvironments $resetter): int
    {
        $key = $this->stringArgument('key');
        $environment = $registry->find($key);

        if ($environment === null || ! $environment->exists) {
            $this->components->error(sprintf('Unknown environment “%s”.', $key));

            return self::FAILURE;
        }

        $reseedFrom = null;
        $reseedKey = $this->option('reseed-from');

        if (is_string($reseedKey) && $reseedKey !== '') {
            $reseedFrom = $registry->find($reseedKey);

            if ($reseedFrom === null || ! $reseedFrom->exists) {
                $this->components->error(sprintf('Unknown reseed-from environment “%s”.', $reseedKey));

                return self::FAILURE;
            }
        }

        try {
            $result = $resetter->reset($environment, $reseedFrom);
        } catch (EnvironmentProtectedException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Reset “%s”: %d row(s) wiped across %d table(s)%s. Config %s.',
            $environment->key,
            $result->totalDeleted(),
            count($result->deletedByTable),
            $reseedFrom !== null ? sprintf(' and re-cloned config from “%s”', $reseedFrom->key) : '',
            $reseedFrom !== null ? 'replaced' : 'kept',
        ));

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
