<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Seller\SellerCatalog;
use App\Models\Invoice;
use RuntimeException;

/**
 * Renders a legal {@see Invoice} to a self-contained PDF (#55) with a manual FPDF layout
 * (see {@see InvoiceDocument}). Nothing is fetched at render time: the document is a pure
 * function of the invoice row, its lines and the selling entity's registered identity, and
 * the only embedded asset is the local Cbox logo.
 *
 * A negative-total invoice is the app's representation of a credit note (the legal reversal
 * of an issued invoice); it renders under the "Credit note" heading with credited amounts.
 */
readonly class InvoicePdfRenderer
{
    public function __construct(
        private SellerCatalog $sellers,
    ) {}

    /** The rendered PDF bytes for `$invoice`. */
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['organization', 'lines']);

        $organization = $invoice->organization;

        if ($organization === null) {
            throw new RuntimeException("Invoice [{$invoice->number}] has no billed organization.");
        }

        $document = new InvoiceDocument(
            $invoice,
            $organization,
            $this->seller($invoice->seller, $invoice->seller_identity),
            $this->isCreditNote($invoice),
            $this->logoPath(),
        );

        $document->build();

        // FPDF's Output() is untyped; with the 'S' destination it returns the PDF as a
        // string. Narrow it at the boundary rather than trusting the mixed return.
        $bytes = $document->Output('S');

        return is_string($bytes) ? $bytes : '';
    }

    /** The download filename for `$invoice` (its legal number). */
    public function filename(Invoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }

    /** A credit note is the app's negative-total invoice — the reversal of an issued one. */
    public function isCreditNote(Invoice $invoice): bool
    {
        return $invoice->total_minor < 0;
    }

    /** The local Cbox logo embedded in the header, or null when it is not present. */
    private function logoPath(): ?string
    {
        $path = public_path('cbox/assets/logo/cbox-logo-h50.png');

        return is_file($path) ? $path : null;
    }

    /**
     * The selling entity's registered identity for the invoice header — the identity FROZEN on
     * the document at issue, falling back to the live register only for rows issued before
     * snapshots existed. An entity resolvable in neither is a hard failure: a legal document must
     * not be mastheaded with a placeholder identity.
     *
     * @param  array<array-key, mixed>|null  $snapshot  The identity frozen on the document at issue.
     * @return array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}
     */
    private function seller(string $seller, ?array $snapshot): array
    {
        return SellerDocumentIdentity::forDocument($this->sellers, $seller, $snapshot);
    }
}
