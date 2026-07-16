<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Support\MoneyFormatter;
use App\Models\Invoice;
use App\Models\Organization;
use FPDF;

/**
 * The manual FPDF layout of a single legal {@see Invoice}. This is a structured document,
 * not arbitrary HTML: every block (header + logo, seller/buyer identity, the line-items
 * table, the tax/credit lines and the totals) is placed by hand at a fixed grid, so the
 * output is a pure function of the invoice row, its lines and the selling entity's
 * registered identity — nothing is fetched at render time.
 *
 * A negative-total invoice is the app's representation of a credit note (the legal reversal
 * of an issued invoice): it renders under the "Credit note" heading and the displayed
 * amounts carry the reversal sign.
 */
class InvoiceDocument extends FPDF
{
    private const MARGIN = 15.0;

    private const RGB_INK = [26, 26, 26];

    private const RGB_MUTED = [107, 107, 107];

    private const RGB_LABEL = [138, 138, 138];

    private const RGB_HAIRLINE = [223, 223, 223];

    /**
     * @param  array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}  $seller
     */
    public function __construct(
        private readonly Invoice $invoice,
        private readonly Organization $organization,
        private readonly array $seller,
        private readonly bool $isCreditNote,
        private readonly ?string $logoPath,
    ) {
        parent::__construct('P', 'mm', 'A4');

        // Uncompressed content streams keep the rendered text legible/extractable in the
        // byte stream rather than gzip-hidden, so the number and total stay greppable.
        $this->SetCompression(false);
        $this->SetAutoPageBreak(true, 24.0);
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetTitle(($this->isCreditNote ? 'Credit note ' : 'Invoice ').$this->invoice->number);
        $this->SetSubject('Total '.MoneyFormatter::minor($this->invoice->total_minor, $this->invoice->currency));
        $this->SetCreator('Cbox Billing');
        $this->SetAuthor($this->seller['legal_name']);
    }

    /** Compose the whole document into the page buffer. */
    public function build(): void
    {
        $this->AddPage();
        $this->documentHeader();
        $this->parties();
        $this->lineItems();
        $this->totals();
    }

    /** The footer band (seller identity + number), drawn on every page by FPDF. */
    public function Footer(): void
    {
        $this->SetY(-18.0);
        $this->hairline($this->cursorY());
        $this->Ln(2.0);
        $this->SetFont('Helvetica', '', 8);
        $this->color(self::RGB_LABEL);

        $parts = [$this->seller['legal_name']];

        if ($this->seller['establishment'] !== null) {
            $parts[] = $this->seller['establishment'];
        }

        $parts[] = $this->invoice->number;

        $this->Cell(0, 5, $this->enc(implode('  |  ', $parts)), 0, 0, 'C');
    }

    /** Logo + seller identity on the left, document title/number/status on the right. */
    private function documentHeader(): void
    {
        $top = $this->cursorY();

        if ($this->logoPath !== null) {
            // 143x50 source ratio; 40mm wide keeps the mark crisp without dominating.
            $this->Image($this->logoPath, self::MARGIN, $top, 40);
            $this->SetY($top + 18.0);
        }

        $identityTop = $this->cursorY();

        $this->color(self::RGB_INK);
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell(110, 6, $this->enc($this->seller['legal_name']), 0, 2);

        $this->SetFont('Helvetica', '', 9);
        $this->color(self::RGB_MUTED);

        if ($this->seller['registration_number'] !== null) {
            $this->Cell(110, 5, $this->enc('Reg. '.$this->seller['registration_number']), 0, 2);
        }

        foreach ($this->seller['tax_registrations'] as $registration) {
            $this->Cell(110, 5, $this->enc('VAT '.$registration['country'].' '.$registration['number']), 0, 2);
        }

        // Right-hand title block, aligned to the top of the header band.
        $rightX = 120.0;
        $rightW = 210.0 - self::MARGIN - $rightX;

        $this->SetXY($rightX, $top);
        $this->color(self::RGB_INK);
        $this->SetFont('Helvetica', 'B', 22);
        $this->Cell($rightW, 10, $this->enc($this->isCreditNote ? 'Credit note' : 'Invoice'), 0, 2, 'R');

        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell($rightW, 6, $this->enc($this->invoice->number), 0, 2, 'R');

        $this->Ln(1.0);
        $this->statusPill($rightX, $rightW);

        // Continue below whichever column ran longer.
        $this->SetY(max($this->cursorY(), $identityTop + 22.0) + 6.0);
    }

    /** A small filled status chip, right-aligned under the invoice number. */
    private function statusPill(float $rightX, float $rightW): void
    {
        $status = strtoupper($this->invoice->status);

        [$fill, $text] = match ($this->invoice->status) {
            'paid' => [[230, 244, 234], [30, 126, 52]],
            'open' => [[253, 240, 227], [181, 105, 26]],
            default => [[238, 238, 238], [102, 102, 102]],
        };

        $this->SetFont('Helvetica', 'B', 8);
        $width = $this->stringWidth($status) + 5.0;
        $x = $rightX + $rightW - $width;
        $y = $this->cursorY();

        $this->SetFillColor($fill[0], $fill[1], $fill[2]);
        $this->Rect($x, $y, $width, 5.0, 'F');
        $this->color($text);
        $this->SetXY($x, $y);
        $this->Cell($width, 5.0, $this->enc($status), 0, 1, 'C', true);
    }

    /** "Billed to" (buyer org) on the left, invoice details on the right. */
    private function parties(): void
    {
        $top = $this->cursorY();
        $colW = 90.0;
        $organization = $this->organization;

        $this->label('Billed to');
        $this->color(self::RGB_INK);
        $this->SetFont('Helvetica', 'B', 10);
        $this->Cell($colW, 5.5, $this->enc($organization->name), 0, 2);
        $this->SetFont('Helvetica', '', 9);
        $this->color(self::RGB_MUTED);

        foreach ([
            $organization->billing_email,
            $organization->billing_country,
            $organization->tax_id !== null ? 'VAT '.$organization->tax_id : null,
        ] as $line) {
            if (is_string($line) && $line !== '') {
                $this->Cell($colW, 5, $this->enc($line), 0, 2);
            }
        }

        $leftBottom = $this->cursorY();

        // Right column — the invoice details.
        $rightX = self::MARGIN + $colW;
        $this->SetXY($rightX, $top);
        $this->label('Details');

        $details = [
            ['Issued', $this->invoice->issued_at?->format('Y-m-d') ?? '—'],
            ['Due', $this->invoice->due_at?->format('Y-m-d') ?? '—'],
        ];

        if ($this->invoice->paid_at !== null) {
            $details[] = ['Paid', $this->invoice->paid_at->format('Y-m-d')];
        }

        $details[] = ['Currency', $this->invoice->currency];

        $this->SetFont('Helvetica', '', 9);

        foreach ($details as [$key, $value]) {
            $this->SetX($rightX);
            $this->color(self::RGB_MUTED);
            $this->Cell(20, 5, $this->enc($key), 0, 0);
            $this->color(self::RGB_INK);
            $this->Cell(0, 5, $this->enc($value), 0, 2);
        }

        $this->SetY(max($leftBottom, $this->cursorY()) + 8.0);
    }

    /** The line-items table: description, qty, unit, net amount. */
    private function lineItems(): void
    {
        $widths = ['desc' => 90.0, 'qty' => 20.0, 'unit' => 35.0, 'amount' => 35.0];

        $this->SetFont('Helvetica', 'B', 8);
        $this->color(self::RGB_LABEL);
        $this->Cell($widths['desc'], 7, $this->enc('DESCRIPTION'), 0, 0);
        $this->Cell($widths['qty'], 7, $this->enc('QTY'), 0, 0, 'R');
        $this->Cell($widths['unit'], 7, $this->enc('UNIT'), 0, 0, 'R');
        $this->Cell($widths['amount'], 7, $this->enc('NET'), 0, 1, 'R');
        $this->hairline($this->cursorY());
        $this->Ln(1.5);

        $this->SetFont('Helvetica', '', 9.5);

        foreach ($this->invoice->lines as $line) {
            $this->color(self::RGB_INK);
            $this->Cell($widths['desc'], 6.5, $this->enc($line->description), 0, 0);
            $this->Cell($widths['qty'], 6.5, $this->enc((string) $line->quantity), 0, 0, 'R');
            $this->Cell($widths['unit'], 6.5, $this->enc($this->money($line->unit_minor)), 0, 0, 'R');
            $this->Cell($widths['amount'], 6.5, $this->enc($this->money($line->amount_minor)), 0, 1, 'R');
            $this->hairlineLight($this->cursorY());
        }

        $this->Ln(4.0);
    }

    /** Net / tax / total, right-aligned; a credit note reverses the sign and relabels. */
    private function totals(): void
    {
        $x = 120.0;
        $labelW = 40.0;
        $valueW = 210.0 - self::MARGIN - $x - $labelW;

        $rows = [
            ['Subtotal', $this->money($this->invoice->subtotal_minor), false],
            ['Tax', $this->money($this->invoice->tax_minor), false],
            [$this->isCreditNote ? 'Total credited' : 'Total due', $this->money($this->invoice->total_minor), true],
        ];

        foreach ($rows as [$label, $value, $grand]) {
            $this->SetX($x);

            if ($grand) {
                $this->hairline($this->cursorY(), $x);
                $this->Ln(1.5);
                $this->SetX($x);
                $this->SetFont('Helvetica', 'B', 12);
                $this->color(self::RGB_INK);
            } else {
                $this->SetFont('Helvetica', '', 9.5);
                $this->color(self::RGB_MUTED);
                $this->Cell($labelW, 6, $this->enc($label), 0, 0);
                $this->color(self::RGB_INK);
                $this->Cell($valueW, 6, $this->enc($value), 0, 1, 'R');

                continue;
            }

            $this->Cell($labelW, 8, $this->enc($label), 0, 0);
            $this->Cell($valueW, 8, $this->enc($value), 0, 1, 'R');
        }
    }

    /** A section eyebrow label. */
    private function label(string $text): void
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->color(self::RGB_LABEL);
        $this->Cell(90, 5, $this->enc(strtoupper($text)), 0, 2);
    }

    /** The invoice/credit amount for `$minor`, carrying the credit-note reversal sign. */
    private function money(int $minor): string
    {
        return MoneyFormatter::minor(($this->isCreditNote ? -1 : 1) * $minor, $this->invoice->currency);
    }

    /** @param array{int, int, int} $rgb */
    private function color(array $rgb): void
    {
        $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /** The current cursor Y as a float — FPDF's untyped accessor narrowed at the boundary. */
    private function cursorY(): float
    {
        $y = $this->GetY();

        return is_float($y) ? $y : 0.0;
    }

    /** The rendered width of `$text` in the current font — FPDF's untyped accessor narrowed. */
    private function stringWidth(string $text): float
    {
        $width = $this->GetStringWidth($text);

        return is_float($width) ? $width : 0.0;
    }

    private function hairline(float $y, ?float $fromX = null): void
    {
        $this->SetDrawColor(self::RGB_HAIRLINE[0], self::RGB_HAIRLINE[1], self::RGB_HAIRLINE[2]);
        $this->Line($fromX ?? self::MARGIN, $y, 210.0 - self::MARGIN, $y);
    }

    private function hairlineLight(float $y): void
    {
        $this->SetDrawColor(239, 239, 239);
        $this->Line(self::MARGIN, $y, 210.0 - self::MARGIN, $y);
    }

    /**
     * Core FPDF fonts are Windows-1252 (WinAnsi); text is transcoded from UTF-8 so
     * accented buyer names and currency punctuation render correctly (and unmappable
     * glyphs degrade to a plain substitute rather than mojibake).
     */
    private function enc(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? $text : $converted;
    }
}
