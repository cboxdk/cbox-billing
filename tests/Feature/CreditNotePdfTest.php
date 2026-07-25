<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\CreditNotePdfRenderer;
use App\Billing\Support\MoneyFormatter;
use App\Models\ApiToken;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Organization;
use App\Models\SellerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature gap #1 closed: credit notes are now downloadable as a legal PDF (the twin of the
 * invoice PDF). The document renders with the right number + amounts, the console route is
 * permission-gated, and the portal route is org-scoped (a cross-org credit note is a 404,
 * never leaking that another account's credit note exists).
 */
class CreditNotePdfTest extends TestCase
{
    use RefreshDatabase;

    private function creditNoteFor(string $org, string $number = 'CN-000042'): CreditNote
    {
        // A legal document is rendered from the seller REGISTER, so the entity issuing it has to
        // exist. Rendering one for an unregistered seller is refused outright — see
        // test_rendering_is_refused_for_an_unregistered_selling_entity below.
        SellerEntity::query()->firstOrCreate(['id' => 'seller_x'], [
            'legal_name' => 'Seller X ApS',
            'registration_number' => 'DK12345678',
            'establishment' => 'DK',
            'currency' => 'DKK',
            'invoice_prefix' => 'SX',
        ]);

        Organization::query()->firstOrCreate(['id' => $org], [
            'name' => ucfirst($org).' Ltd',
            'billing_email' => $org.'@example.test',
            'billing_country' => 'DK',
        ]);

        $note = CreditNote::query()->create([
            'number' => $number, 'invoice_number' => 'CBOX-'.$org.'-1', 'organization_id' => $org,
            'seller' => 'seller_x', 'currency' => 'DKK', 'net_minor' => 8_000, 'tax_minor' => 2_000,
            'gross_minor' => 10_000, 'reason' => 'Goodwill', 'kind' => 'adjustment', 'issued_at' => now(),
        ]);

        CreditNoteLine::query()->create([
            'credit_note_id' => $note->id, 'description' => 'Refunded seat', 'quantity' => 2,
            'net_minor' => 8_000, 'tax_minor' => 2_000, 'gross_minor' => 10_000,
        ]);

        return $note;
    }

    public function test_the_renderer_produces_a_pdf_with_the_number_and_amounts(): void
    {
        $note = $this->creditNoteFor('org_cn');

        $pdf = app(CreditNotePdfRenderer::class)->render($note->fresh());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString($note->number, $pdf);
        // Uncompressed streams keep the total greppable; the credited gross is present.
        $this->assertStringContainsString(MoneyFormatter::minor(10_000, 'DKK'), $pdf);
        $this->assertStringContainsString('Org_cn Ltd', $pdf);
        $this->assertSame('CN-000042.pdf', app(CreditNotePdfRenderer::class)->filename($note));
    }

    public function test_the_console_route_downloads_the_pdf_for_an_operator(): void
    {
        $note = $this->creditNoteFor('org_cn2');

        $this->withSession(['auth.user' => [
            'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test',
            'org' => 'Cbox Systems', 'picture' => null,
        ]]);

        $response = $this->get(route('billing.credit-notes.pdf', $note->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString($note->number.'.pdf', (string) $response->headers->get('content-disposition'));
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString($note->number, $body);
    }

    public function test_the_portal_route_downloads_the_customers_own_credit_note(): void
    {
        $note = $this->creditNoteFor('org_owner');
        $token = $this->portalToken('org_owner');

        $response = $this->get(route('hosted.portal.credit-note-pdf', ['token' => $token, 'creditNote' => $note->id]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * The register is the source of truth for a document masthead. An operator who registers a
     * selling entity in the console must see THAT identity on the PDF — previously the renderer
     * read the config only, so a console-registered seller produced a document mastheaded with
     * the raw key, no registration number and no VAT line. Such an invoice fails EU Directive
     * 2006/112/EC Art. 226(3) and 226(5), and nothing errored to say so.
     */
    public function test_the_document_carries_the_registered_legal_identity(): void
    {
        $note = $this->creditNoteFor('org_ident');

        SellerEntity::query()->where('id', 'seller_x')->update([
            'legal_name' => 'Acme GmbH',
            'registration_number' => 'HRB 12345',
            'establishment' => 'DE',
        ]);

        $pdf = app(CreditNotePdfRenderer::class)->render($note->fresh());

        $this->assertStringContainsString('Acme GmbH', $pdf);
        $this->assertStringContainsString('HRB 12345', $pdf);
        $this->assertStringNotContainsString('seller_x', $pdf);
    }

    /**
     * A legal document must never be mastheaded with a placeholder identity, so an entity in
     * neither the register nor the config is refused rather than rendered with the bare key.
     */
    public function test_rendering_is_refused_for_an_unregistered_selling_entity(): void
    {
        $note = $this->creditNoteFor('org_unreg');
        SellerEntity::query()->where('id', 'seller_x')->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/registered neither in the seller register nor/');

        app(CreditNotePdfRenderer::class)->render($note->fresh());
    }

    public function test_the_portal_route_404s_a_cross_org_credit_note(): void
    {
        // A credit note owned by ANOTHER organization.
        $other = $this->creditNoteFor('org_other', 'CN-999');
        $token = $this->portalToken('org_owner2');

        $this->get(route('hosted.portal.credit-note-pdf', ['token' => $token, 'creditNote' => $other->id]))
            ->assertNotFound();
    }

    private function portalToken(string $org): string
    {
        Organization::query()->firstOrCreate(['id' => $org], [
            'name' => ucfirst($org), 'billing_email' => $org.'@example.test', 'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        $response = $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        return basename((string) parse_url((string) $response->json('url'), PHP_URL_PATH));
    }
}
