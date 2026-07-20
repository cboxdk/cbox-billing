<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Environments\Contracts\ResetsEnvironments;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\OperatorAuditEvent;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit recorder stamps the ACTUAL named plane on each event (not a binary collapse of the
 * livemode mirror), and the global append-only trail is NEVER bulk-deleted by a plane teardown —
 * so resetting/destroying a sandbox that holds audit rows succeeds (no BEFORE-DELETE trigger
 * collision) and keeps those rows.
 */
class AuditEnvironmentStampingTest extends TestCase
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

    private function recordIn(string $environmentKey, string $summary): void
    {
        $this->inEnvironment($environmentKey, function () use ($summary): void {
            app(RecordsAudit::class)->record(
                AuditAction::InvoiceCreated,
                AuditTarget::of('invoice', 'inv_1', 'org_acme'),
                $summary,
            );
        });
    }

    public function test_an_action_in_a_named_sandbox_records_that_environment_key(): void
    {
        $this->recordIn('production', 'in production');

        app(CreatesEnvironments::class)->create(key: 'ci-42');
        $this->recordIn('ci-42', 'in the named sandbox');

        $this->assertSame('ci-42', OperatorAuditEvent::query()->where('summary', 'in the named sandbox')->firstOrFail()->getAttribute('environment'));
        $this->assertSame('production', OperatorAuditEvent::query()->where('summary', 'in production')->firstOrFail()->getAttribute('environment'));
    }

    public function test_destroying_a_sandbox_with_audit_rows_succeeds_and_keeps_them(): void
    {
        $environment = app(CreatesEnvironments::class)->create(key: 'ci-teardown')->environment;
        $this->recordIn('ci-teardown', 'sandbox audit row');

        $before = OperatorAuditEvent::query()->where('environment', 'ci-teardown')->count();
        $this->assertSame(1, $before);

        // Neither reset nor destroy may raise the append-only trigger collision (formerly a 500) …
        app(ResetsEnvironments::class)->reset($environment);
        app(DestroysEnvironments::class)->destroy($environment);

        // … and the global trail keeps the sandbox's rows (chain continuity preserved).
        $this->assertSame(1, OperatorAuditEvent::query()->where('environment', 'ci-teardown')->count());
    }
}
