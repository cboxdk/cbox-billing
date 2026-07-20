<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Notifications\Branding\SellerBranding;
use PHPUnit\Framework\TestCase;

/**
 * The seller accent drives white-label CTAs and badges, so the text painted on top of it must
 * stay AA-legible: {@see SellerBranding::onBrandColor()} picks white or near-black by whichever
 * yields the higher WCAG contrast against the accent. A pale brand colour must NOT keep white text.
 */
class SellerBrandingContrastTest extends TestCase
{
    private function branding(string $brandColor): SellerBranding
    {
        return new SellerBranding(
            sellerEntityId: null,
            productName: 'Acme',
            brandColor: $brandColor,
            logoUrl: null,
            fromName: 'Acme',
            fromEmail: 'billing@acme.test',
            replyTo: null,
            legalName: 'Acme Inc',
            registrationNumber: '',
            footerAddress: null,
            supportUrl: null,
            supportEmail: null,
        );
    }

    public function test_dark_accent_gets_white_text(): void
    {
        // The default blue accent is dark — white text has the higher contrast.
        $this->assertSame('#ffffff', $this->branding('#2743b3')->onBrandColor());
        $this->assertSame('#ffffff', $this->branding('#000000')->onBrandColor());
    }

    public function test_pale_accent_gets_dark_text(): void
    {
        // A bright yellow / white accent would fail AA with white text — pick near-black instead.
        $this->assertSame('#111318', $this->branding('#ffe600')->onBrandColor());
        $this->assertSame('#111318', $this->branding('#ffffff')->onBrandColor());
    }

    public function test_three_digit_hex_is_supported(): void
    {
        $this->assertSame('#111318', $this->branding('#fff')->onBrandColor());
        $this->assertSame('#ffffff', $this->branding('#000')->onBrandColor());
    }

    public function test_unparseable_colour_falls_back_to_white(): void
    {
        $this->assertSame('#ffffff', $this->branding('not-a-colour')->onBrandColor());
    }
}
