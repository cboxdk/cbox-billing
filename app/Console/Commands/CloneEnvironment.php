<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use Illuminate\Console\Command;

/**
 * Clone a billing environment's CONFIG into a brand-new sandbox plane. Thin adapter over the
 * {@see ClonesEnvironments} contract: resolves the source environment (default production),
 * refuses an unknown source, and reports the outcome. The deep copy, relationship preservation
 * and secret-blanking all live in the service.
 *
 *   php artisan environment:clone production acme-test --name="Acme Test"
 */
class CloneEnvironment extends Command
{
    protected $signature = 'environment:clone {source : The environment to copy config FROM (e.g. production)} {newKey : The new sandbox environment key} {--name= : Human label for the new environment}';

    protected $description = 'Clone an environment: create a sandbox plane and deep-copy the source plane\'s config (no tenant data).';

    public function handle(EnvironmentRegistry $registry, ClonesEnvironments $cloner): int
    {
        $sourceKey = $this->argument('source');
        $newKey = $this->argument('newKey');
        $name = $this->option('name');

        $source = $registry->find($sourceKey);

        if ($source === null || ! $source->exists) {
            $this->components->error(sprintf('Unknown source environment “%s”.', $sourceKey));

            return self::FAILURE;
        }

        try {
            $target = $cloner->clone($source, $newKey, is_string($name) ? $name : null);
        } catch (EnvironmentCloneException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Cloned “%s” → “%s” (%s). Config copied; the new plane starts with an empty book and test gateway keys.',
            $source->key,
            $target->key,
            $target->name,
        ));

        return self::SUCCESS;
    }
}
