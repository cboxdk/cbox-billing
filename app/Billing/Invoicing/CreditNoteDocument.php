<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Support\MoneyFormatter;
use App\Billing\Support\WeightedAllocator;
use App\Models\CreditNote;
use App\Models\Organization;
use FPDF;

/**
 * The manual FPDF layout of a legal {@see CreditNote} — the downloadable twin of
 * {@see InvoiceDocument}, same visual grammar (header + logo, seller/buyer identity, the
 * line-items table, the totals block) so a credit note and the invoice it reverses read as one
 * pair. A pure function of the credit-note row, its lines and the selling entity's registered
 * identity; nothing is fetched at render time and the only embedded asset is the local logo.
 *
 * Credit-note amounts are stored as POSITIVE magnitudes (the reversal sign is the document's
 * meaning — money returned to the customer), so they print as shown under a "Total credited"
 * heading, with the reversed invoice referenced in the details.
 */
class CreditNoteDocument extends FPDF
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
        private readonly CreditNote $creditNote,
        private readonly Organization $organization,
        private readonly array $seller,
        private readonly ?string $logoPath,
    ) {
        parent::__construct('P', 'mm', 'A4');

        $this->SetCompression(false);
        $this->SetAutoPageBreak(true, 24.0);
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetTitle('Credit note '.$this->creditNote->number);
        $this->SetSubject('Total credited '.MoneyFormatter::minor($this->creditNote->gross_minor, $this->creditNote->currency));
        $this->SetCreator('Cbox Billing');
        $this->SetAuthor($this->seller['legal_name']);
    }

    public function build(): void
    {
        $this->AddPage();
        $this->documentHeader();
        $this->parties();
        $this->lineItems();
        $this->totals();
    }

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

        $parts[] = $this->creditNote->number;

        $this->Cell(0, 5, $this->enc(implode('  |  ', $parts)), 0, 0, 'C');
    }

    private function documentHeader(): void
    {
        $top = $this->cursorY();

        if ($this->logoPath !== null) {
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

        $rightX = 120.0;
        $rightW = 210.0 - self::MARGIN - $rightX;

        $this->SetXY($rightX, $top);
        $this->color(self::RGB_INK);
        $this->SetFont('Helvetica', 'B', 22);
        $this->Cell($rightW, 10, $this->enc('Credit note'), 0, 2, 'R');

        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell($rightW, 6, $this->enc($this->creditNote->number), 0, 2, 'R');

        $this->SetY(max($this->cursorY(), $identityTop + 22.0) + 6.0);
    }

    private function parties(): void
    {
        $top = $this->cursorY();
        $colW = 90.0;
        $organization = $this->organization;

        $this->label('Credited to');
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

        $rightX = self::MARGIN + $colW;
        $this->SetXY($rightX, $top);
        $this->label('Details');

        $details = [
            ['Issued', $this->creditNote->issued_at->format('Y-m-d')],
            ['Reverses', $this->creditNote->invoice_number],
            ['Currency', $this->creditNote->currency],
        ];

        $this->SetFont('Helvetica', '', 9);

        foreach ($details as [$key, $value]) {
            $this->SetX($rightX);
            $this->color(self::RGB_MUTED);
            $this->Cell(22, 5, $this->enc($key), 0, 0);
            $this->color(self::RGB_INK);
            $this->Cell(0, 5, $this->enc($value), 0, 2);
        }

        $this->SetY(max($leftBottom, $this->cursorY()) + 8.0);
    }

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

        foreach ($this->creditNote->lines as $line) {
            $unitMinor = WeightedAllocator::unitMinor($line->net_minor, $line->quantity);

            $this->color(self::RGB_INK);
            $this->Cell($widths['desc'], 6.5, $this->enc($line->description), 0, 0);
            $this->Cell($widths['qty'], 6.5, $this->enc((string) $line->quantity), 0, 0, 'R');
            $this->Cell($widths['unit'], 6.5, $this->enc($this->money($unitMinor)), 0, 0, 'R');
            $this->Cell($widths['amount'], 6.5, $this->enc($this->money($line->net_minor)), 0, 1, 'R');
            $this->hairlineLight($this->cursorY());
        }

        $this->Ln(4.0);
    }

    private function totals(): void
    {
        $x = 120.0;
        $labelW = 40.0;
        $valueW = 210.0 - self::MARGIN - $x - $labelW;

        $rows = [
            ['Subtotal', $this->money($this->creditNote->net_minor), false],
            ['Tax', $this->money($this->creditNote->tax_minor), false],
            ['Total credited', $this->money($this->creditNote->gross_minor), true],
        ];

        foreach ($rows as [$label, $value, $grand]) {
            $this->SetX($x);

            if ($grand) {
                $this->hairline($this->cursorY(), $x);
                $this->Ln(1.5);
                $this->SetX($x);
                $this->SetFont('Helvetica', 'B', 12);
                $this->color(self::RGB_INK);
                $this->Cell($labelW, 8, $this->enc($label), 0, 0);
                $this->Cell($valueW, 8, $this->enc($value), 0, 1, 'R');

                continue;
            }

            $this->SetFont('Helvetica', '', 9.5);
            $this->color(self::RGB_MUTED);
            $this->Cell($labelW, 6, $this->enc($label), 0, 0);
            $this->color(self::RGB_INK);
            $this->Cell($valueW, 6, $this->enc($value), 0, 1, 'R');
        }
    }

    private function label(string $text): void
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->color(self::RGB_LABEL);
        $this->Cell(90, 5, $this->enc(strtoupper($text)), 0, 2);
    }

    /** A stored positive magnitude for `$minor` — the reversal sign is the document's meaning. */
    private function money(int $minor): string
    {
        return MoneyFormatter::minor($minor, $this->creditNote->currency);
    }

    /** @param array{int, int, int} $rgb */
    private function color(array $rgb): void
    {
        $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function cursorY(): float
    {
        $y = $this->GetY();

        return is_float($y) ? $y : 0.0;
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
     * Core FPDF fonts are Windows-1252 (WinAnsi); text is transcoded from UTF-8 so accented
     * buyer names and currency punctuation render correctly.
     */
    private function enc(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? $text : $converted;
    }
}
