<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Support\MoneyFormatter;
use Cbox\Billing\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Locale-aware money presentation: continental locales (Danish) group with a dot and use a
 * comma decimal; English-family locales are the reverse. The integer-minor value drives it —
 * only the presentation separators change per locale.
 */
class MoneyFormatterLocaleTest extends TestCase
{
    public function test_danish_groups_with_dot_and_comma_decimal(): void
    {
        $this->assertSame('DKK 1.240,00', MoneyFormatter::forLocale(Money::ofMinor(124000, 'DKK'), 'da'));
    }

    public function test_english_groups_with_comma_and_dot_decimal(): void
    {
        $this->assertSame('DKK 1,240.00', MoneyFormatter::forLocale(Money::ofMinor(124000, 'DKK'), 'en'));
    }

    public function test_a_regional_locale_tag_resolves_by_its_language(): void
    {
        $this->assertSame('DKK 1,240.00', MoneyFormatter::forLocale(Money::ofMinor(124000, 'DKK'), 'en-US'));
        $this->assertSame('DKK 1.240,00', MoneyFormatter::forLocale(Money::ofMinor(124000, 'DKK'), 'da_DK'));
    }
}
