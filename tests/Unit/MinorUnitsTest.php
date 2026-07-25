<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Support\MinorUnits;
use App\Billing\Support\MoneyFormatter;
use Brick\Money\Exception\UnknownCurrencyException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The write side of the money seam. The cases are chosen per EXPONENT CLASS rather than per
 * currency, because the defect being pinned is a hardcoded ×100: it is invisible on two-decimal
 * currencies and shows only on the zero- and three-decimal ones.
 */
class MinorUnitsTest extends TestCase
{
    /** @return list<array{string, string, int}> */
    public static function exponentCases(): array
    {
        return [
            // Two decimals — what a ×100 parser got right. Kept as the regression floor.
            ['1234.56', 'EUR', 123456],
            ['50', 'DKK', 5000],
            ['9.5', 'USD', 950],

            // Zero decimals: ¥1500 is 1500 minor. A ×100 parser stored 150000 — a 100×
            // overcharge on a quote the customer accepts and the invoice then collects.
            ['1500', 'JPY', 1500],
            ['15000', 'KRW', 15000],

            // Three decimals: 1.500 BHD is 1500 minor. A ×100 parser stored 150 — 10× under.
            ['1.500', 'BHD', 1500],
            ['0.250', 'KWD', 250],
            ['2', 'TND', 2000],
        ];
    }

    #[DataProvider('exponentCases')]
    public function test_it_scales_by_the_currency_exponent_not_a_hardcoded_100(string $input, string $currency, int $expected): void
    {
        $this->assertSame($expected, MinorUnits::parse($input, $currency));
    }

    public function test_it_rounds_half_up_rather_than_truncating(): void
    {
        // The old parser truncated the third decimal: "1.999" became 199, not 200.
        $this->assertSame(200, MinorUnits::parse('1.999', 'EUR'));
        $this->assertSame(199, MinorUnits::parse('1.994', 'EUR'));
        $this->assertSame(1, MinorUnits::parse('1.4', 'JPY'));
        $this->assertSame(2, MinorUnits::parse('1.5', 'JPY'));
    }

    public function test_the_parsed_amount_renders_back_through_the_formatter_it_shares_a_currency_with(): void
    {
        // Parse and render must agree about what the stored integer means. The two halves
        // disagreeing is precisely what made the ×100 bug invisible on the order form.
        $this->assertSame('JPY 1.500', MoneyFormatter::minor(MinorUnits::parse('1500', 'JPY'), 'JPY'));
        $this->assertSame('BHD 1,500', MoneyFormatter::minor(MinorUnits::parse('1.500', 'BHD'), 'BHD'));
        $this->assertSame('EUR 1.234,56', MoneyFormatter::minor(MinorUnits::parse('1234.56', 'EUR'), 'EUR'));
    }

    public function test_it_handles_negatives_and_refuses_to_invent_an_amount(): void
    {
        $this->assertSame(-5000, MinorUnits::parse('-50', 'DKK'));
        $this->assertSame(0, MinorUnits::parse('', 'DKK'));
        $this->assertSame(0, MinorUnits::parse('abc', 'DKK'));
        $this->assertSame(0, MinorUnits::parse(null, 'DKK'));
        $this->assertSame(0, MinorUnits::parse([], 'DKK'));
    }

    public function test_it_refuses_a_currency_whose_exponent_it_cannot_know(): void
    {
        // Guessing an exponent is how the original bug happened. The boundary rule
        // (IsoCurrency) turns this into a 422 rather than letting an unpriceable code through.
        $this->expectException(UnknownCurrencyException::class);

        MinorUnits::parse('10.00', 'ZZZ');
    }
}
