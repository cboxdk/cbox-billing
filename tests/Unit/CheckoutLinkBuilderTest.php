<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Storefront\CheckoutLinkBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The pricing-table CTA deep-link contract (#57): a template with placeholders is substituted;
 * a template without them (or the configured fallback) gets the params appended as a query.
 */
class CheckoutLinkBuilderTest extends TestCase
{
    public function test_placeholders_are_substituted_and_url_encoded(): void
    {
        $builder = new CheckoutLinkBuilder('/');

        $url = $builder->build('https://app.test/checkout?plan={plan}&cur={currency}&iv={interval}&p={price}', 'team', 'EUR', 'year', 169000);

        $this->assertSame('https://app.test/checkout?plan=team&cur=EUR&iv=year&p=169000', $url);
    }

    public function test_a_template_without_placeholders_gets_the_params_appended(): void
    {
        $builder = new CheckoutLinkBuilder('/');

        $url = $builder->build('https://app.test/signup', 'starter', 'USD', 'month', 4500);

        $this->assertSame('https://app.test/signup?plan=starter&currency=USD&interval=month&price=4500', $url);
    }

    public function test_falls_back_to_the_default_target_when_no_template_is_set(): void
    {
        $builder = new CheckoutLinkBuilder('/');

        $this->assertSame('/?plan=team&currency=EUR&interval=month&price=16900', $builder->build(null, 'team', 'EUR', 'month', 16900));
        $this->assertSame('/?plan=team&currency=EUR&interval=month&price=16900', $builder->build('   ', 'team', 'EUR', 'month', 16900));
    }

    public function test_appends_with_ampersand_when_the_target_already_has_a_query(): void
    {
        $builder = new CheckoutLinkBuilder('https://app.test/buy?ref=pricing');

        $url = $builder->build(null, 'business', 'DKK', 'month', 349000);

        $this->assertSame('https://app.test/buy?ref=pricing&plan=business&currency=DKK&interval=month&price=349000', $url);
    }
}
