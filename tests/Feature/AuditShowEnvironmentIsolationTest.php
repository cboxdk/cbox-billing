<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\OperatorAuditEvent;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 5 (P2) — the `/audit/{event}` detail view must be scoped to the current environment. The
 * trail is a single global, append-only hash chain (the model carries NO environment global scope),
 * so an unscoped route-model binding let an event from plane A be read while switched to plane B.
 * The show action now asserts the event belongs to the current plane (404 otherwise), matching the
 * per-plane index.
 */
class AuditShowEnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $auth = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EnvironmentSeeder::class);
    }

    private function recordIn(string $environmentKey, string $summary): OperatorAuditEvent
    {
        $environment = Environment::query()->where('key', $environmentKey)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, function () use ($summary): OperatorAuditEvent {
            app(RecordsAudit::class)->record(
                AuditAction::InvoiceCreated,
                AuditTarget::of('invoice', 'inv_1', 'org_acme'),
                $summary,
            );

            return OperatorAuditEvent::query()->withoutGlobalScopes()->where('summary', $summary)->firstOrFail();
        });
    }

    public function test_an_event_is_only_viewable_from_its_own_environment(): void
    {
        app(CreatesEnvironments::class)->create(key: 'ci-audit');

        $productionEvent = $this->recordIn('production', 'a production audit event');
        $sandboxEvent = $this->recordIn('ci-audit', 'a named-sandbox audit event');

        // On production (default console plane): the production event is viewable, the sandbox one 404s.
        $this->withSession($this->auth)->get('/audit/'.$productionEvent->id)->assertOk();
        $this->withSession($this->auth)->get('/audit/'.$sandboxEvent->id)->assertNotFound();

        // Switched to the named sandbox: the reverse — its own event is viewable, production's 404s.
        $sandboxSession = ['console.environment' => 'ci-audit'] + $this->auth;
        $this->withSession($sandboxSession)->get('/audit/'.$sandboxEvent->id)->assertOk();
        $this->withSession($sandboxSession)->get('/audit/'.$productionEvent->id)->assertNotFound();
    }
}
