<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Reporting\PricingReport;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Metering\Contracts\EventLog;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usage & invoice-detail depth (#55): the per-meter usage breakdown (used / included /
 * overage / projected) reconciled from the event log, the invoice PDF download (console +
 * portal, per-org scoped), and the catalog-driven plan-comparison / pricing view.
 */
class UsageInvoicePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    /** @return array<string, string> */
    private function tokenFor(string $org): array
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function operatorSession(): void
    {
        $this->withSession(['auth.user' => [
            'sub' => 'demo|tester',
            'name' => 'Test Operator',
            'email' => 'ops@example.test',
            'org' => 'Cbox Systems',
            'picture' => null,
        ]]);
    }

    public function test_usage_breakdown_reconciles_to_the_event_log(): void
    {
        $organization = Organization::query()->create([
            'id' => 'org_breakdown',
            'name' => 'Breakdown',
            'billing_email' => 'breakdown@example.test',
            'billing_country' => 'DK',
        ]);

        $plan = Plan::query()->where('key', 'team')->firstOrFail();
        app(SubscribesOrganizations::class)->subscribe($organization, $plan, seats: 4);

        // Land 600,000 ingested events (Team includes 500,000) through the durable ingest.
        $this->postJson('/api/v1/usage', [
            'org' => 'org_breakdown',
            'entries' => [['meter' => 'events.ingested', 'cumulative' => 600_000, 'seq' => 1]],
        ], $this->tokenFor('org_breakdown'))->assertOk();

        $response = $this->getJson('/api/v1/usage/org_breakdown', $this->tokenFor('org_breakdown'));
        $response->assertOk();

        // Meter keys contain dots, so index the decoded payload rather than a dotted path.
        $meter = $response->json('meters')['events.ingested'];

        // `used` is exactly the reconciled total in the durable event log.
        [$fromMs, $toMs] = $this->periodMillis($response->json('period'));
        $ledgerUsed = app(EventLog::class)->sum('org_breakdown', 'events.ingested', $fromMs, $toMs);

        $this->assertSame(600_000, $meter['used']);
        $this->assertSame(600_000, $ledgerUsed);
        $this->assertSame(500_000, $meter['allowance']);
        $this->assertSame(100_000, $meter['overage']);
        $this->assertSame(max(0, $meter['used'] - $meter['allowance']), $meter['overage']);

        // The projection extrapolates past what has been used and reconciles with its overage.
        $this->assertGreaterThanOrEqual($meter['used'], $meter['projected']);
        $this->assertSame(max(0, $meter['projected'] - $meter['allowance']), $meter['projected_overage']);
    }

    /** @return array{0: int, 1: int} */
    private function periodMillis(mixed $period): array
    {
        $start = is_array($period) && is_string($period['start'] ?? null) ? strtotime($period['start']) : 0;
        $end = is_array($period) && is_string($period['end'] ?? null) ? strtotime($period['end']) : 0;

        return [$start * 1000, $end * 1000];
    }

    public function test_console_invoice_pdf_downloads_with_number_and_total(): void
    {
        $this->operatorSession();

        $invoice = Invoice::query()->where('organization_id', 'org_hverdag')->firstOrFail();

        $response = $this->get('/invoices/'.$invoice->id.'/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString($invoice->number.'.pdf', (string) $response->headers->get('content-disposition'));

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString($invoice->number, $body);
        // The formatted total is present in the document.
        $this->assertStringContainsString('DKK 1.240,00', $body);
    }

    public function test_portal_invoice_pdf_is_scoped_to_the_session_organization(): void
    {
        $sessions = app(ManagesBillingSessions::class);
        $organization = Organization::query()->findOrFail('org_hverdag');
        $session = $sessions->openPortal($organization, 'https://app.example.test/return');

        $ownInvoice = Invoice::query()->where('organization_id', 'org_hverdag')->firstOrFail();
        $foreignInvoice = Invoice::query()->where('organization_id', 'org_nordwind')->firstOrFail();

        // The account can download its own invoice.
        $ok = $this->get('/billing/portal/'.$session->token.'/invoices/'.$ownInvoice->id.'/pdf');
        $ok->assertOk();
        $this->assertSame('application/pdf', $ok->headers->get('content-type'));

        // Another org's invoice is not reachable through this session — deny-by-default 404.
        $this->get('/billing/portal/'.$session->token.'/invoices/'.$foreignInvoice->id.'/pdf')
            ->assertNotFound();
    }

    public function test_pricing_view_renders_per_currency(): void
    {
        $this->operatorSession();

        $this->get('/pricing')->assertOk()
            ->assertSee('Starter')->assertSee('Scale')
            ->assertSee('DKK 1.240,00'); // Team, DKK

        $this->get('/pricing?currency=EUR')->assertOk()
            ->assertSee('EUR 169,00'); // Team, EUR
    }

    public function test_pricing_report_marks_legacy_plans_and_hides_them_from_public_cards(): void
    {
        Plan::query()->where('key', 'starter')->update(['active' => false]);

        $report = app(PricingReport::class);
        $comparison = $report->comparison();

        $starter = collect($comparison['plans'])->firstWhere('key', 'starter');
        $this->assertTrue($starter['legacy']);

        // The public cards exclude legacy plans by default but include them on request.
        $publicKeys = array_column($report->cards('DKK'), 'key');
        $this->assertNotContains('starter', $publicKeys);
        $this->assertContains('starter', array_column($report->cards('DKK', includeLegacy: true), 'key'));
    }
}
