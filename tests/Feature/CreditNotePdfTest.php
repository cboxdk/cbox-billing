<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\CreditNotePdfRenderer;
use App\Billing\Invoicing\SellerDocumentIdentity;
use App\Billing\Seller\SellerAuthoring;
use App\Billing\Seller\SellerCatalog;
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
            // Mirrors what PersistIssuedCreditNote does in production: the seller's legal identity
            // is FROZEN onto the document at issue, so a later edit to the register cannot rewrite
            // an already-issued document.
            'seller_identity' => SellerDocumentIdentity::resolve(app(SellerCatalog::class), 'seller_x'),
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
     *
     * The identity is set BEFORE the document is created, deliberately. An earlier version of this
     * test mutated the register after issuing and asserted the PDF changed — which would have
     * codified retroactive mutation of an issued legal document as intended behaviour. It is not:
     * the content of an issued invoice is fixed. Snapshotting identity at issue time is tracked
     * separately; this test asserts only that a registered identity reaches the document.
     */
    public function test_the_document_carries_the_registered_legal_identity(): void
    {
        $this->creditNoteFor('org_seed'); // ensures the seller row exists

        SellerEntity::query()->where('id', 'seller_x')->update([
            'legal_name' => 'Acme GmbH',
            'registration_number' => 'HRB 12345',
            'establishment' => 'DE',
        ]);

        // Issued AFTER the identity is in place — the normal ordering.
        $note = $this->creditNoteFor('org_ident', 'CN-000043');

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

        // A document issued BEFORE snapshots existed has nothing frozen to fall back on, so an
        // entity since removed from both the register and the config leaves nothing truthful to
        // print. (A document WITH a snapshot renders fine in the same situation — that is the
        // point of the snapshot, and it is asserted separately.)
        $note->forceFill(['seller_identity' => null])->save();

        SellerEntity::query()->where('id', 'seller_x')->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/registered identity could not be resolved/');

        app(CreditNotePdfRenderer::class)->render($note->fresh());
    }

    /**
     * The refusal is correct for new issuance, but it changes the failure mode for HISTORICAL
     * documents: one whose seller was removed from the register since it was issued now errors on
     * download rather than rendering a wrong PDF. `billing:verify-seller-identities` exists so a
     * release finds that before a customer does.
     */
    public function test_the_verify_command_flags_an_issued_document_whose_seller_no_longer_resolves(): void
    {
        $this->creditNoteFor('org_verify');

        $this->artisan('billing:verify-seller-identities')->assertExitCode(0);

        SellerEntity::query()->where('id', 'seller_x')->delete();

        $this->artisan('billing:verify-seller-identities')
            ->expectsOutputToContain('no longer resolve')
            ->assertExitCode(1);
    }

    /**
     * The delete guard counted invoices by NUMBER PREFIX, but documents reference the seller by
     * id — and the prefix is editable while minted numbers are never renumbered. So a routine
     * prefix change let a still-referenced entity be hard-deleted, after which the customer-facing
     * portal PDF route 500s. Credit notes were never counted at all.
     */
    public function test_a_seller_referenced_only_by_a_credit_note_cannot_be_deleted(): void
    {
        $this->creditNoteFor('org_guard');

        $seller = SellerEntity::query()->findOrFail('seller_x');

        // Rename the prefix, as an operator renumbering a series would.
        $seller->forceFill(['invoice_prefix' => 'RENAMED'])->save();

        $this->assertGreaterThan(
            0,
            app(SellerAuthoring::class)->invoicesFor($seller->fresh()),
            'A credit note still references this seller, so the delete guard must see it even '
            .'after the invoice-number prefix changed.',
        );
    }

    /**
     * The content of an issued invoice is FIXED (EU Directive 2006/112/EC). Rendering read the
     * live register, so an operator correcting a legal name — or an establishment country, which
     * is the tax jurisdiction printed — silently rewrote every document that seller had ever
     * issued. The exposure grew when rendering moved from config (deploy-gated) to the register
     * (editable by any operator with the console permission).
     */
    public function test_editing_the_register_does_not_alter_an_already_issued_document(): void
    {
        $note = $this->creditNoteFor('org_frozen');

        $before = app(CreditNotePdfRenderer::class)->render($note->fresh());
        $this->assertStringContainsString('Seller X ApS', $before);

        SellerEntity::query()->where('id', 'seller_x')->update([
            'legal_name' => 'Renamed Holding GmbH',
            'registration_number' => 'HRB 99999',
            'establishment' => 'DE',
        ]);

        $after = app(CreditNotePdfRenderer::class)->render($note->fresh());

        $this->assertStringContainsString('Seller X ApS', $after);
        $this->assertStringNotContainsString('Renamed Holding GmbH', $after);
        $this->assertStringNotContainsString('HRB 99999', $after);
    }

    /**
     * A document issued before the snapshot column existed has nothing frozen on it, and there is
     * nothing truthful to backfill — inventing a snapshot from the CURRENT register would be the
     * very thing this prevents. Such a row keeps rendering from the live register, exactly as it
     * did before.
     */
    /** A snapshotted document survives the seller being removed entirely — no 500 on download. */
    public function test_a_snapshotted_document_still_renders_after_the_seller_is_deleted(): void
    {
        $note = $this->creditNoteFor('org_gone');

        SellerEntity::query()->where('id', 'seller_x')->delete();

        $pdf = app(CreditNotePdfRenderer::class)->render($note->fresh());

        $this->assertStringContainsString('Seller X ApS', $pdf);
    }

    public function test_a_document_without_a_snapshot_falls_back_to_the_live_register(): void
    {
        $note = $this->creditNoteFor('org_legacy');
        $note->forceFill(['seller_identity' => null])->save();

        SellerEntity::query()->where('id', 'seller_x')->update(['legal_name' => 'Live Register ApS']);

        $pdf = app(CreditNotePdfRenderer::class)->render($note->fresh());

        $this->assertStringContainsString('Live Register ApS', $pdf);
    }

    /** A malformed snapshot is not trusted — a blank masthead is worse than the live register. */
    public function test_an_unusable_snapshot_falls_back_rather_than_rendering_a_blank(): void
    {
        $note = $this->creditNoteFor('org_broken');
        $note->forceFill(['seller_identity' => ['key' => 'seller_x', 'legal_name' => '']])->save();

        $pdf = app(CreditNotePdfRenderer::class)->render($note->fresh());

        $this->assertStringContainsString('Seller X ApS', $pdf);
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
