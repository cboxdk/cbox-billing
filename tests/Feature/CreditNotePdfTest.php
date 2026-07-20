<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\CreditNotePdfRenderer;
use App\Billing\Support\MoneyFormatter;
use App\Models\ApiToken;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
