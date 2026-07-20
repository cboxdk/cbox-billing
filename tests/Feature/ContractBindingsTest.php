<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Coupons\Contracts\DiscountsAmounts;
use App\Billing\Coupons\Contracts\RedeemsCoupons;
use App\Billing\Coupons\CouponDiscounter;
use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Experiments\Contracts\AttributesConversions;
use App\Billing\Experiments\ConversionAttribution;
use App\Billing\Features\Contracts\ResolvesFeatureEntitlements;
use App\Billing\Features\FeatureEntitlements;
use App\Billing\Storefront\CheckoutLinkBuilder;
use App\Billing\Storefront\Contracts\BuildsCheckoutLinks;
use Tests\TestCase;

/**
 * Contracts-first DI: the four modules built without interfaces now expose one, bound to their
 * concrete in the provider — so callers depend on the contract (money paths especially). The
 * feature resolver's contract must resolve the SAME singleton the flush hook targets.
 */
class ContractBindingsTest extends TestCase
{
    public function test_each_module_contract_resolves_to_its_concrete(): void
    {
        $this->assertInstanceOf(CouponRedeemer::class, app(RedeemsCoupons::class));
        $this->assertInstanceOf(CouponDiscounter::class, app(DiscountsAmounts::class));
        $this->assertInstanceOf(ConversionAttribution::class, app(AttributesConversions::class));
        $this->assertInstanceOf(FeatureEntitlements::class, app(ResolvesFeatureEntitlements::class));
        $this->assertInstanceOf(CheckoutLinkBuilder::class, app(BuildsCheckoutLinks::class));
    }

    public function test_the_feature_entitlements_contract_is_the_same_singleton_the_flush_hook_targets(): void
    {
        // The interface must alias the concrete singleton, or a grant/override write flushing
        // app(FeatureEntitlements) would leave a stale instance behind the contract.
        $this->assertSame(
            app(FeatureEntitlements::class),
            app(ResolvesFeatureEntitlements::class),
        );
    }
}
