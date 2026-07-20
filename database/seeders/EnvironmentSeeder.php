<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Environments\EnvironmentType;
use App\Billing\Environments\GatewayKeyMode;
use App\Models\Environment;
use Illuminate\Database\Seeder;

/**
 * Seeds the two baseline planes every install starts with: PRODUCTION (the real, protected,
 * live-gateway plane — the legacy `livemode = true`) and SANDBOX (an isolated, disposable,
 * test-gateway dataset — the legacy `livemode = false`). Idempotent (`updateOrCreate` on the
 * stable key), so re-seeding never duplicates a plane or clobbers an operator's edits to it.
 */
class EnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        Environment::query()->updateOrCreate(
            ['key' => Environment::PRODUCTION],
            [
                'name' => 'Production',
                'type' => EnvironmentType::Production,
                'protected' => true,
                'gateway_key_mode' => GatewayKeyMode::Live,
            ],
        );

        Environment::query()->updateOrCreate(
            ['key' => Environment::SANDBOX],
            [
                'name' => 'Sandbox',
                'type' => EnvironmentType::Sandbox,
                'protected' => false,
                'gateway_key_mode' => GatewayKeyMode::Test,
            ],
        );
    }
}
