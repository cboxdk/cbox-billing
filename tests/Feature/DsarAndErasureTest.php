<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Approvals\Enums\ApprovalStatus;
use App\Billing\Audit\Contracts\AssemblesDsarBundle;
use App\Billing\Audit\Contracts\RedactsSubjectData;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Tax\Exemptions\ExemptionCertificateService;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxExemptionCertificate;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PharData;
use Tests\TestCase;

/**
 * GDPR / DSAR tooling: the data-subject access bundle assembles the subject's records across
 * datasets (and is itself audit-logged); erasure pseudonymizes PII (name/email/tax id →
 * tombstone, certificate document deleted) while the invoice rows + totals survive, records a
 * `data.erased` event, and a re-export afterwards shows tombstones, not PII.
 */
class DsarAndErasureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> The maker: the operator who initiates the DSAR action. */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    /** @var array<string, mixed> A DISTINCT second operator, to satisfy the erase two-person rule. */
    private array $checker = ['auth.user' => [
        'sub' => 'demo|checker', 'name' => 'Checker Operator', 'email' => 'checker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-07-18 10:00:00');
    }

    private function subjectWithHistory(string $org = 'org_dsar'): Invoice
    {
        Organization::query()->create([
            'id' => $org, 'name' => 'Acme GmbH', 'billing_email' => 'ap@acme.example',
            'billing_country' => 'DK', 'billing_currency' => 'DKK', 'tax_id' => 'DK99999999',
        ]);

        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $subscription = Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $team->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 20,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);

        $invoice = app(GeneratesInvoices::class)->generate($subscription->refresh());

        // An operator action so the trail carries an org-scoped audit event too.
        $this->withSession($this->session)->post('/customers/'.$org.'/suspend');

        return $invoice;
    }

    /** @return array<string, string> entryName => content, read from a built tar.gz bundle */
    private function readBundle(string $path): array
    {
        $out = [];
        $phar = new PharData($path);

        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            /** @var \PharFileInfo $file */
            $out[$file->getFilename()] = $file->getContent();
        }

        return $out;
    }

    public function test_the_dsar_bundle_contains_the_subjects_records_across_datasets_and_is_audit_logged(): void
    {
        $invoice = $this->subjectWithHistory();

        $bundle = app(AssemblesDsarBundle::class)->build(Organization::query()->findOrFail('org_dsar'), true);
        $files = $this->readBundle($bundle->path);

        $this->assertArrayHasKey('manifest.json', $files);
        $this->assertArrayHasKey('invoices.ndjson', $files);
        $this->assertArrayHasKey('subscriptions.ndjson', $files);
        $this->assertArrayHasKey('audit_events.ndjson', $files);

        // An invoice, a subscription and an audit event are all present for the subject.
        $this->assertStringContainsString($invoice->number, $files['invoices.ndjson']);
        $this->assertStringContainsString('org_dsar', $files['subscriptions.ndjson']);
        $this->assertStringContainsString(AuditAction::CustomerSuspended->value, $files['audit_events.ndjson']);

        @unlink($bundle->path);

        // The HTTP export is itself audit-logged as dsar.exported.
        $this->withSession($this->session)->get('/audit/gdpr/org_dsar/export')->assertOk();
        $this->assertSame(1, OperatorAuditEvent::query()->where('action', AuditAction::DsarExported->value)->where('organization_id', 'org_dsar')->count());
    }

    public function test_a_single_operator_erase_is_held_for_approval_and_destroys_nothing(): void
    {
        Storage::fake(ExemptionCertificateService::DISK);
        $this->subjectWithHistory();

        Storage::disk(ExemptionCertificateService::DISK)->put('certs/acme.pdf', 'PDF-BYTES');
        TaxExemptionCertificate::query()->create([
            'organization_id' => 'org_dsar', 'jurisdiction' => 'DK', 'exemption_type' => 'resale',
            'certificate_number' => 'CERT-1', 'status' => 'verified',
            'document_path' => 'certs/acme.pdf', 'document_name' => 'acme.pdf', 'document_mime' => 'application/pdf', 'document_size' => 9,
        ]);

        // A single operator initiates the erase — it is HELD (maker-checker), not applied.
        $this->withSession($this->session)->post('/audit/gdpr/org_dsar/erase')
            ->assertRedirect()->assertSessionHas('status');

        // A pending approval was captured; NOTHING was destroyed.
        $request = ApprovalRequest::query()->firstOrFail();
        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame('data.erase', $request->action_type->value);
        $this->assertSame('demo|tester', $request->requested_by_sub);

        $org = Organization::query()->findOrFail('org_dsar');
        $this->assertFalse($org->isErased());
        $this->assertNotNull($org->billing_email);
        Storage::disk(ExemptionCertificateService::DISK)->assertExists('certs/acme.pdf');
        $this->assertDatabaseMissing('operator_audit_events', ['action' => AuditAction::DataErased->value]);
    }

    public function test_a_second_operator_approves_and_the_erasure_redacts_pii_but_retains_the_financial_records(): void
    {
        Storage::fake(ExemptionCertificateService::DISK);
        $invoice = $this->subjectWithHistory();

        // A stored certificate document to be deleted by erasure.
        Storage::disk(ExemptionCertificateService::DISK)->put('certs/acme.pdf', 'PDF-BYTES');
        TaxExemptionCertificate::query()->create([
            'organization_id' => 'org_dsar', 'jurisdiction' => 'DK', 'exemption_type' => 'resale',
            'certificate_number' => 'CERT-1', 'status' => 'verified',
            'document_path' => 'certs/acme.pdf', 'document_name' => 'acme.pdf', 'document_mime' => 'application/pdf', 'document_size' => 9,
        ]);

        $total = $invoice->total_minor;

        // Maker initiates (held); a DIFFERENT operator approves — only then is anything destroyed.
        $this->withSession($this->session)->post('/audit/gdpr/org_dsar/erase');
        $request = ApprovalRequest::query()->firstOrFail();
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve', ['note' => 'DSAR verified'])
            ->assertRedirect('/approvals')->assertSessionHas('status');

        $org = Organization::query()->findOrFail('org_dsar');
        $this->assertStringContainsString('[erased organization', (string) $org->name);
        $this->assertNull($org->billing_email);
        $this->assertNull($org->tax_id);
        $this->assertNotNull($org->erased_at);
        $this->assertTrue($org->isErased());

        // The certificate document is gone from disk; the tax row survives, de-identified.
        Storage::disk(ExemptionCertificateService::DISK)->assertMissing('certs/acme.pdf');
        $cert = TaxExemptionCertificate::query()->where('organization_id', 'org_dsar')->firstOrFail();
        $this->assertNull($cert->document_path);

        // The invoice row + totals survive untouched (statutory retention).
        $keptInvoice = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame($total, $keptInvoice->total_minor);

        // An erasure audit event exists and records only field names/counts — never the old PII.
        $event = OperatorAuditEvent::query()->where('action', AuditAction::DataErased->value)->firstOrFail();
        $this->assertContains('name', ($event->metadata ?? [])['redacted_fields'] ?? []);
        $blob = $event->summary.'|'.json_encode($event->metadata ?? []);
        $this->assertStringNotContainsString('Acme GmbH', $blob);
        $this->assertStringNotContainsString('DK99999999', $blob);
    }

    public function test_a_reexport_after_erasure_shows_tombstones_not_pii(): void
    {
        Storage::fake(ExemptionCertificateService::DISK);
        $this->subjectWithHistory();

        app(RedactsSubjectData::class)->erase(Organization::query()->findOrFail('org_dsar'));

        $bundle = app(AssemblesDsarBundle::class)->build(Organization::query()->findOrFail('org_dsar'), true);
        $files = $this->readBundle($bundle->path);

        $this->assertArrayHasKey('customers.ndjson', $files);
        $this->assertStringNotContainsString('Acme GmbH', $files['customers.ndjson']);
        $this->assertStringNotContainsString('DK99999999', $files['customers.ndjson']);
        $this->assertStringContainsString('[erased organization', $files['customers.ndjson']);

        @unlink($bundle->path);
    }
}
