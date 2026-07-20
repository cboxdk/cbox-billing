<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Models\CreditNote;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Renders a legal {@see CreditNote} to a self-contained PDF — the downloadable twin of
 * {@see InvoicePdfRenderer}. Nothing is fetched at render time: the document is a pure function
 * of the credit-note row, its lines and the selling entity's registered identity (resolved
 * through the shared {@see SellerDocumentIdentity}), and the only embedded asset is the local
 * Cbox logo.
 */
readonly class CreditNotePdfRenderer
{
    public function __construct(
        private Config $config,
    ) {}

    /** The rendered PDF bytes for `$creditNote`. */
    public function render(CreditNote $creditNote): string
    {
        $creditNote->loadMissing(['organization', 'lines']);

        $organization = $creditNote->organization;

        if ($organization === null) {
            throw new RuntimeException("Credit note [{$creditNote->number}] has no credited organization.");
        }

        $document = new CreditNoteDocument(
            $creditNote,
            $organization,
            SellerDocumentIdentity::resolve($this->config, $creditNote->seller),
            $this->logoPath(),
        );

        $document->build();

        // FPDF's Output('S') returns the PDF as a string; narrow the untyped return at the edge.
        $bytes = $document->Output('S');

        return is_string($bytes) ? $bytes : '';
    }

    /** The download filename for `$creditNote` (its legal number). */
    public function filename(CreditNote $creditNote): string
    {
        return $creditNote->number.'.pdf';
    }

    /** The local Cbox logo embedded in the header, or null when it is not present. */
    private function logoPath(): ?string
    {
        $path = public_path('cbox/assets/logo/cbox-logo-h50.png');

        return is_file($path) ? $path : null;
    }
}
