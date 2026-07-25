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

    /**
     * A zero-decimal currency has no fractional part at all. Formatting ¥15,000 (minor = 15000)
     * as "JPY 150,00" — what a hardcoded `minor / 100` produces — understates the amount 100×
     * on an invoice PDF, which is a legal document.
     */
    public function test_a_zero_decimal_currency_renders_without_decimals(): void
    {
        $this->assertSame('JPY 15.000', MoneyFormatter::forLocale(Money::ofMinor(15000, 'JPY'), 'da'));
        $this->assertSame('JPY 15,000', MoneyFormatter::forLocale(Money::ofMinor(15000, 'JPY'), 'en'));
        $this->assertSame('KRW 1.234.567', MoneyFormatter::forLocale(Money::ofMinor(1234567, 'KRW'), 'da'));
    }

    /** A three-decimal currency keeps all three — 1500 fils is BHD 1.500, not BHD 15.00. */
    public function test_a_three_decimal_currency_renders_three_decimals(): void
    {
        $this->assertSame('BHD 1,500', MoneyFormatter::forLocale(Money::ofMinor(1500, 'BHD'), 'da'));
        $this->assertSame('BHD 1.500', MoneyFormatter::forLocale(Money::ofMinor(1500, 'BHD'), 'en'));
        $this->assertSame('KWD 12,340', MoneyFormatter::forLocale(Money::ofMinor(12340, 'KWD'), 'da'));
        $this->assertSame('TND 1.234,567', MoneyFormatter::forLocale(Money::ofMinor(1234567, 'TND'), 'da'));
    }

    public function test_negative_and_zero_amounts_keep_the_currency_exponent(): void
    {
        $this->assertSame('DKK -1.240,00', MoneyFormatter::forLocale(Money::ofMinor(-124000, 'DKK'), 'da'));
        $this->assertSame('JPY -15.000', MoneyFormatter::forLocale(Money::ofMinor(-15000, 'JPY'), 'da'));
        $this->assertSame('JPY 0', MoneyFormatter::forLocale(Money::ofMinor(0, 'JPY'), 'da'));
        $this->assertSame('DKK 0,05', MoneyFormatter::forLocale(Money::ofMinor(5, 'DKK'), 'da'));
    }

    /** The `money()` and `minor()` shorthands must carry the same exponent correctness. */
    public function test_the_shorthand_helpers_honour_the_currency_exponent(): void
    {
        $this->assertSame('JPY 15.000', MoneyFormatter::money(Money::ofMinor(15000, 'JPY')));
        $this->assertSame('JPY 15.000', MoneyFormatter::minor(15000, 'JPY'));
        $this->assertSame('BHD 1,500', MoneyFormatter::minor(1500, 'BHD'));
    }
}
