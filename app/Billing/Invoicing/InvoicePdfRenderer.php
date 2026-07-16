<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Support\MoneyFormatter;
use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders a legal {@see Invoice} to a self-contained PDF (#55). The document is built from
 * a Blade template into fully inlined HTML and rasterised by Dompdf with remote fetching
 * disabled — nothing is pulled off the network at render time, so the PDF is a pure
 * function of the invoice row, its lines and the selling entity's registered identity.
 *
 * A negative-total invoice is the app's representation of a credit note (the legal reversal
 * of an issued invoice); it renders under the "Credit note" heading with credited amounts.
 */
readonly class InvoicePdfRenderer
{
    public function __construct(
        private ViewFactory $views,
        private Config $config,
    ) {}

    /** The rendered PDF bytes for `$invoice`. */
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['organization', 'lines']);

        $html = $this->views->make('invoices.pdf', [
            'invoice' => $invoice,
            'seller' => $this->seller($invoice->seller),
            'isCreditNote' => $this->isCreditNote($invoice),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        // A core PDF font, un-subsetted, so text is emitted as literal WinAnsi strings
        // (kept legible/extractable in the byte stream) rather than hex-mapped subset glyphs.
        $options->set('defaultFont', 'helvetica');
        $options->set('isFontSubsettingEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4');

        // The legal number and total live in the (uncompressed) document info dictionary so
        // the document is identifiable from its metadata, not only its rendered glyphs.
        $label = $this->isCreditNote($invoice) ? 'Credit note' : 'Invoice';
        $dompdf->addInfo('Title', $label.' '.$invoice->number);
        $dompdf->addInfo('Subject', 'Total '.MoneyFormatter::minor($invoice->total_minor, $invoice->currency));

        $dompdf->render();

        // Uncompressed content streams keep the rendered text legible/extractable rather
        // than gzip-hidden.
        return $dompdf->output(['compress' => 0]);
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

    /**
     * The selling entity's registered identity for the invoice header, from the seller
     * config. Falls back to just the seller key when the entity is not configured, rather
     * than inventing legal details.
     *
     * @return array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}
     */
    private function seller(string $seller): array
    {
        $entities = $this->config->get('billing.seller.entities', []);
        $entity = is_array($entities) && is_array($entities[$seller] ?? null) ? $entities[$seller] : [];

        $legalName = $entity['legal_name'] ?? null;
        $registration = $entity['registration_number'] ?? null;
        $establishment = $entity['establishment'] ?? null;

        $registrations = [];

        foreach (is_array($entity['tax_registrations'] ?? null) ? $entity['tax_registrations'] : [] as $registrationRow) {
            if (is_array($registrationRow) && is_string($registrationRow['country'] ?? null) && is_string($registrationRow['number'] ?? null)) {
                $registrations[] = ['country' => $registrationRow['country'], 'number' => $registrationRow['number']];
            }
        }

        return [
            'key' => $seller,
            'legal_name' => is_string($legalName) ? $legalName : $seller,
            'registration_number' => is_string($registration) ? $registration : null,
            'establishment' => is_string($establishment) ? $establishment : null,
            'tax_registrations' => $registrations,
        ];
    }
}
