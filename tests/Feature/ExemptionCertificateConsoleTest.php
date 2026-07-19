<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Tax\Exemptions\ExemptionStatus;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\TaxExemptionCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The exemption-certificate operations surface: upload validation, the operator verify/reject
 * lifecycle (recording the operator), the scheduled expire sweep, secure (private-disk)
 * storage, and the authz-gated document download.
 */
class ExemptionCertificateConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function org(string $id = 'org_x'): Organization
    {
        return Organization::query()->create([
            'id' => $id, 'name' => 'Buyer '.$id, 'billing_email' => $id.'@example.test',
            'billing_country' => 'US', 'billing_subdivision' => 'US-CA', 'billing_currency' => 'USD',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'jurisdiction' => 'US-CA',
            'exemption_type' => 'resale',
            'certificate_number' => 'RESALE-CA-77',
            'document' => UploadedFile::fake()->create('cert.pdf', 120, 'application/pdf'),
        ], $overrides);
    }

    public function test_operator_upload_stores_the_document_on_the_private_disk_as_pending(): void
    {
        $org = $this->org();

        $this->withSession($this->session)
            ->post('/customers/'.$org->id.'/exemptions', $this->payload())
            ->assertRedirect(route('billing.customers.show', $org->id));

        $cert = TaxExemptionCertificate::query()->where('organization_id', $org->id)->firstOrFail();

        $this->assertSame(ExemptionStatus::Pending, $cert->status);
        $this->assertNotNull($cert->document_path);
        $this->assertStringStartsWith('tax-exemptions/'.$org->id.'/', (string) $cert->document_path);

        // Stored on the PRIVATE disk, never the public one.
        Storage::disk('local')->assertExists($cert->document_path);
        Storage::disk('public')->assertMissing($cert->document_path);
    }

    public function test_upload_refuses_a_bad_file_type_and_a_past_expiry(): void
    {
        $org = $this->org();

        // A .txt is not an accepted certificate document.
        $this->withSession($this->session)
            ->from('/customers/'.$org->id)
            ->post('/customers/'.$org->id.'/exemptions', $this->payload([
                'document' => UploadedFile::fake()->create('cert.txt', 10, 'text/plain'),
            ]))
            ->assertSessionHasErrors('document');

        // An expiry in the past is refused (a live exemption needs a future expiry).
        $this->withSession($this->session)
            ->from('/customers/'.$org->id)
            ->post('/customers/'.$org->id.'/exemptions', $this->payload([
                'expires_at' => Carbon::now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('expires_at');

        $this->assertSame(0, TaxExemptionCertificate::query()->count());
    }

    public function test_verify_and_reject_flip_status_and_record_the_operator(): void
    {
        $org = $this->org();
        $verified = TaxExemptionCertificate::query()->create([
            'organization_id' => $org->id, 'jurisdiction' => 'US-CA', 'exemption_type' => 'resale',
            'certificate_number' => 'V-1', 'status' => ExemptionStatus::Pending,
        ]);
        $rejected = TaxExemptionCertificate::query()->create([
            'organization_id' => $org->id, 'jurisdiction' => 'US-NY', 'exemption_type' => 'nonprofit',
            'certificate_number' => 'R-1', 'status' => ExemptionStatus::Pending,
        ]);

        $this->withSession($this->session)->post('/customers/'.$org->id.'/exemptions/'.$verified->id.'/verify')->assertRedirect();
        $this->withSession($this->session)->post('/customers/'.$org->id.'/exemptions/'.$rejected->id.'/reject', ['notes' => 'Illegible scan'])->assertRedirect();

        $verified->refresh();
        $rejected->refresh();

        $this->assertSame(ExemptionStatus::Verified, $verified->status);
        $this->assertSame('demo|tester', $verified->verified_by_sub);
        $this->assertNotNull($verified->verified_at);

        $this->assertSame(ExemptionStatus::Rejected, $rejected->status);
        $this->assertSame('demo|tester', $rejected->verified_by_sub);
        $this->assertSame('Illegible scan', $rejected->notes);
    }

    public function test_download_is_authz_gated_to_the_owning_org(): void
    {
        $owner = $this->org('org_owner');
        $other = $this->org('org_other');

        $this->withSession($this->session)->post('/customers/'.$owner->id.'/exemptions', $this->payload());
        $cert = TaxExemptionCertificate::query()->where('organization_id', $owner->id)->firstOrFail();

        // The owning org can download.
        $this->withSession($this->session)
            ->get('/customers/'.$owner->id.'/exemptions/'.$cert->id.'/download')
            ->assertOk();

        // A cross-org request (another org's route with this cert id) is denied (404).
        $this->withSession($this->session)
            ->get('/customers/'.$other->id.'/exemptions/'.$cert->id.'/download')
            ->assertNotFound();
    }

    public function test_expire_command_marks_past_expiry_certificates_expired(): void
    {
        $org = $this->org();

        $lapsed = TaxExemptionCertificate::query()->create([
            'organization_id' => $org->id, 'jurisdiction' => 'US-CA', 'exemption_type' => 'resale',
            'certificate_number' => 'L-1', 'status' => ExemptionStatus::Verified, 'expires_at' => Carbon::now()->subDay(),
        ]);
        $live = TaxExemptionCertificate::query()->create([
            'organization_id' => $org->id, 'jurisdiction' => 'US-NY', 'exemption_type' => 'resale',
            'certificate_number' => 'L-2', 'status' => ExemptionStatus::Verified, 'expires_at' => Carbon::now()->addYear(),
        ]);

        $this->artisan('tax:expire-certificates')->assertSuccessful();

        $this->assertSame(ExemptionStatus::Expired, $lapsed->refresh()->status);
        $this->assertSame(ExemptionStatus::Verified, $live->refresh()->status);
    }

    public function test_portal_customer_can_self_upload_a_pending_certificate(): void
    {
        $org = $this->org('org_portal');
        $session = $this->portalSession($org->id);

        $this->post('/billing/portal/'.$session->token.'/exemptions', $this->payload([
            'certificate_number' => 'PORTAL-CA-9',
        ]))->assertRedirect(route('hosted.portal.show', $session->token));

        $cert = TaxExemptionCertificate::query()->where('organization_id', $org->id)->firstOrFail();

        $this->assertSame(ExemptionStatus::Pending, $cert->status);
        $this->assertSame('PORTAL-CA-9', $cert->certificate_number);
        Storage::disk('local')->assertExists($cert->document_path);
    }

    private function portalSession(string $org): BillingSession
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        return BillingSession::query()->where('organization_id', $org)->where('type', 'portal')->firstOrFail();
    }
}
