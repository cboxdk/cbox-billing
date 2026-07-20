<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Branding;

/**
 * The resolved, ready-to-render branding for one transactional email: the selling entity's
 * customer-facing identity with every app-level default already filled in. The email layout
 * reads only this — it never touches a model or config — so what the console previews is
 * exactly what ships.
 */
readonly class SellerBranding
{
    public function __construct(
        public ?string $sellerEntityId,
        public string $productName,
        public string $brandColor,
        public ?string $logoUrl,
        public string $fromName,
        public string $fromEmail,
        public ?string $replyTo,
        public string $legalName,
        public string $registrationNumber,
        public ?string $footerAddress,
        public ?string $supportUrl,
        public ?string $supportEmail,
    ) {}

    /**
     * The legal footer line — the entity of record + its registration number. Emails must
     * carry the issuing legal identity, so this is never empty when a legal name is known.
     */
    public function legalLine(): string
    {
        if ($this->registrationNumber !== '') {
            return $this->legalName.' · '.$this->registrationNumber;
        }

        return $this->legalName;
    }

    /**
     * An AA-legible text colour to paint ON TOP of {@see brandColor} — the accent drives
     * white-label CTAs and badges, so a pale brand colour (bright yellow, mint, …) must not
     * keep white text that fails WCAG contrast. We pick pure white or near-black by whichever
     * yields the higher WCAG contrast ratio against the accent; an unparseable colour falls
     * back to white (the historical default). Reused by the hosted paywall, storefront pricing
     * table and order form so every seller-branded surface stays readable.
     */
    public function onBrandColor(): string
    {
        $rgb = $this->parseHex($this->brandColor);
        if ($rgb === null) {
            return '#ffffff';
        }

        $luminance = $this->relativeLuminance($rgb);
        $contrastWhite = 1.05 / ($luminance + 0.05);
        $contrastBlack = ($luminance + 0.05) / 0.05;

        return $contrastWhite >= $contrastBlack ? '#ffffff' : '#111318';
    }

    /**
     * Parse a `#rgb` / `#rrggbb` hex string into 0–255 channels, or null if it is not a hex
     * colour. Brand colours are validated as hex on the way in, so this covers every stored value.
     *
     * @return array{int, int, int}|null
     */
    private function parseHex(string $color): ?array
    {
        $hex = ltrim(trim($color), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * WCAG 2.1 relative luminance of an sRGB colour.
     *
     * @param  array{int, int, int}  $rgb
     */
    private function relativeLuminance(array $rgb): float
    {
        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }
}
