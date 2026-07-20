<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Mode\BillingContext;
use App\Billing\Notifications\MailEventType;
use App\Billing\Notifications\NotificationPreferenceService;
use App\Models\Environment;
use App\Models\NotificationPreference;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A notification opt-out is per-ENVIRONMENT: a sandbox portal opt-out for an org must never
 * suppress the SAME org's optional emails on the production plane (both directions), and it must
 * be torn down with the sandbox.
 */
class NotificationPreferenceEnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    public function test_a_sandbox_opt_out_does_not_affect_production_and_vice_versa(): void
    {
        $service = app(NotificationPreferenceService::class);
        $event = MailEventType::RenewalReminder;

        // The org opts OUT in the sandbox portal.
        $this->inEnvironment('sandbox', fn () => $service->setOptedIn('org_acme', $event, false));

        // Production is unaffected — the courtesy mail still sends there.
        $this->assertTrue(
            $this->inEnvironment('production', fn () => $service->allows('org_acme', $event)),
            'a sandbox opt-out must not suppress the production plane',
        );
        // … while the sandbox itself honours the opt-out.
        $this->assertFalse($this->inEnvironment('sandbox', fn () => $service->allows('org_acme', $event)));

        // The other direction: an opt-out in production does not leak into the sandbox.
        $this->inEnvironment('production', fn () => $service->setOptedIn('org_beta', $event, false));
        $this->assertFalse($this->inEnvironment('production', fn () => $service->allows('org_beta', $event)));
        $this->assertTrue(
            $this->inEnvironment('sandbox', fn () => $service->allows('org_beta', $event)),
            'a production opt-out must not suppress the sandbox plane',
        );
    }

    public function test_destroying_a_sandbox_removes_its_notification_preferences(): void
    {
        $service = app(NotificationPreferenceService::class);
        $event = MailEventType::RenewalReminder;

        $environment = app(CreatesEnvironments::class)->create(key: 'sbx-notif')->environment;

        $this->inEnvironment('sbx-notif', fn () => $service->setOptedIn('org_acme', $event, false));
        $this->inEnvironment('production', fn () => $service->setOptedIn('org_acme', $event, false));

        $this->assertSame(1, NotificationPreference::query()->withoutGlobalScopes()->where('environment', 'sbx-notif')->count());

        app(DestroysEnvironments::class)->destroy($environment);

        // The sandbox's rows are gone; production's survive.
        $this->assertSame(0, NotificationPreference::query()->withoutGlobalScopes()->where('environment', 'sbx-notif')->count());
        $this->assertSame(1, NotificationPreference::query()->withoutGlobalScopes()->where('environment', 'production')->count());
    }
}
