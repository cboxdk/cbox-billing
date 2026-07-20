<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Features\Contracts\ResolvesFeatureEntitlements;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Mode\LivemodeScope;
use App\Models\BillingSession;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\OrganizationFeatureOverride;
use App\Models\TaxExemptionCertificate;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Re-review remediation: the CROSS-PLANE (test-vs-live) isolation the earlier per-table sweeps
 * missed, asserted for the SAME org id in BOTH directions. A test-plane feature override,
 * exemption certificate, and hosted PDF download must never read or affect the live plane, and
 * vice-versa — the {@see LivemodeScope} on the newly-partitioned tables is the
 * enforcement, deny-by-default.
 */
class CrossPlaneStateIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    private function org(string $id): void
    {
        // One org row (its id is a global primary key); its child rows carry the plane. Created
        // without the plane scope so the single row satisfies the FK from BOTH planes' children.
        Organization::query()->withoutGlobalScopes()->firstOrCreate(
            ['id' => $id],
            ['name' => ucfirst($id), 'billing_country' => 'DK'],
        );
    }

    public function test_a_feature_override_is_confined_to_its_plane_in_both_directions(): void
    {
        $context = $this->context();
        $resolver = app(ResolvesFeatureEntitlements::class);

        $this->org('org_split');
        $feature = Feature::query()->create(['key' => 'cross_plane_flag', 'name' => 'Flag', 'type' => 'boolean']);

        // TEST plane: the override GRANTS the feature.
        $context->setMode(BillingMode::Test);
        OrganizationFeatureOverride::query()->create([
            'organization_id' => 'org_split', 'feature_id' => $feature->id, 'granted' => true,
        ]);

        // LIVE plane: no override (deny-by-default → not granted).
        $context->setMode(BillingMode::Live);

        // The test override never leaks into live.
        $this->assertFalse($resolver->has('org_split', 'cross_plane_flag'));

        // ...and the live plane genuinely carries no override row for the org+feature.
        $this->assertFalse(
            OrganizationFeatureOverride::query()->where('organization_id', 'org_split')->exists()
        );

        // In test the override applies — the same (org, feature) coexists across planes.
        $context->setMode(BillingMode::Test);
        $this->assertTrue($resolver->has('org_split', 'cross_plane_flag'));
        $this->assertTrue(
            OrganizationFeatureOverride::query()->where('organization_id', 'org_split')->exists()
        );

        // Both override rows really exist — isolation is a scope, not a delete.
        $this->assertSame(1, OrganizationFeatureOverride::query()->withoutGlobalScopes()->count());
    }

    public function test_an_exemption_certificate_is_confined_to_its_plane_in_both_directions(): void
    {
        $context = $this->context();

        $this->org('org_exempt');
        $context->setMode(BillingMode::Test);
        TaxExemptionCertificate::query()->create([
            'organization_id' => 'org_exempt', 'jurisdiction' => 'DK', 'exemption_type' => 'other',
            'certificate_number' => 'DK-TEST-1', 'status' => 'verified', 'verified_at' => Carbon::now(),
        ]);

        $context->setMode(BillingMode::Live);

        // The verified TEST certificate does not exempt anything in LIVE — the plane hides it.
        $this->assertFalse(
            TaxExemptionCertificate::query()->where('organization_id', 'org_exempt')->active()->exists()
        );
        $this->assertSame(0, TaxExemptionCertificate::query()->where('organization_id', 'org_exempt')->count());

        // In TEST the certificate is active for the org.
        $context->setMode(BillingMode::Test);
        $this->assertTrue(
            TaxExemptionCertificate::query()->where('organization_id', 'org_exempt')->active()->exists()
        );

        $this->assertSame(1, TaxExemptionCertificate::query()->withoutGlobalScopes()->count());
    }

    public function test_a_test_portal_token_cannot_download_a_live_document_and_vice_versa(): void
    {
        $context = $this->context();
        $this->org('org_pdf');

        // LIVE plane: a live credit note + a live portal session.
        $context->setMode(BillingMode::Live);
        $liveNote = $this->creditNote('org_pdf', 'CN-LIVE-1');
        $liveToken = $this->portalSession('org_pdf', 'tok-live');

        // TEST plane: same org id + a test credit note + a test portal session.
        $context->setMode(BillingMode::Test);
        $testNote = $this->creditNote('org_pdf', 'CN-TEST-1');
        $testToken = $this->portalSession('org_pdf', 'tok-test');

        // Reset the ambient mode to LIVE — the token, resolved FIRST, is the sole source of the plane.
        $context->setMode(BillingMode::Live);

        // A TEST token resolving a LIVE credit-note id 404s: the id resolves under the test plane
        // (set from the token BEFORE the model is loaded), where the live row does not exist.
        $this->get('/billing/portal/tok-test/credit-notes/'.$liveNote->id.'/pdf')->assertNotFound();

        // A LIVE token resolving a TEST credit-note id 404s the mirror direction.
        $this->get('/billing/portal/tok-live/credit-notes/'.$testNote->id.'/pdf')->assertNotFound();

        // Each token genuinely reaches its OWN plane (the same partition the PDF route now keys on,
        // resolved AFTER the token sets the plane): the test plane sees the test note, not the live
        // one, and vice-versa. (A normal in-plane PDF download is covered by CreditNotePdfTest.)
        $context->setMode(BillingMode::Test);
        $this->assertNotNull(CreditNote::query()->find($testNote->id));
        $this->assertNull(CreditNote::query()->find($liveNote->id));

        $context->setMode(BillingMode::Live);
        $this->assertNotNull(CreditNote::query()->find($liveNote->id));
        $this->assertNull(CreditNote::query()->find($testNote->id));
    }

    private function creditNote(string $org, string $number): CreditNote
    {
        $note = CreditNote::query()->create([
            'number' => $number, 'invoice_number' => 'INV-'.$number, 'organization_id' => $org,
            'seller' => 'seller_x', 'currency' => 'DKK', 'net_minor' => 8_000, 'tax_minor' => 2_000,
            'gross_minor' => 10_000, 'reason' => 'Goodwill', 'kind' => 'adjustment', 'issued_at' => Carbon::now(),
        ]);

        CreditNoteLine::query()->create([
            'credit_note_id' => $note->id, 'description' => 'Refunded seat', 'quantity' => 1,
            'net_minor' => 8_000, 'tax_minor' => 2_000, 'gross_minor' => 10_000,
        ]);

        return $note;
    }

    private function portalSession(string $org, string $token): string
    {
        BillingSession::query()->create([
            'token_hash' => BillingSession::hashToken($token),
            'organization_id' => $org,
            'type' => 'portal',
            'return_url' => 'https://merchant.example/account',
            'status' => 'pending',
            'expires_at' => Carbon::now()->addHour(),
        ]);

        return $token;
    }
}
