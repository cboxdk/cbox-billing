<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

/**
 * Builds a pricing-table CTA's deep-link — the hand-off from a PUBLIC, pre-customer pricing
 * page into the operator's checkout entry point, carrying the chosen `{plan}` / `{currency}` /
 * `{interval}` / `{price}` (ADR-0009 Path A boundary).
 *
 * Why a hand-off and not a hosted-checkout URL directly: the hosted checkout is addressed by an
 * opaque, ORG-scoped session token ({@see App\Billing\Hosted\BillingSessionService}) — but a
 * marketing-page visitor has no organization yet, so the table cannot mint a session. Instead it
 * links to the operator's own checkout/signup entry (their authenticated app), which receives the
 * plan+currency+interval and calls `POST /api/v1/checkout-sessions` to mint the real hosted
 * checkout URL. The paywall (which DOES have an org in hand) links straight to the hosted checkout
 * via the {@see App\Billing\Enforcement\Upgrade\UpgradeGate} instead.
 *
 * Target resolution, deny-by-default at each step:
 *  1. The table's own `cta_url_template` when set.
 *  2. Else the configured storefront checkout URL (`billing.storefront.checkout_url`).
 *  3. Else the app root — so the CTA is always a valid link, never a dead `#`.
 *
 * A target containing any `{...}` placeholder has them substituted (URL-encoded); a target with
 * none gets the params appended as a query string (merged with any it already carries).
 */
readonly class CheckoutLinkBuilder
{
    public function __construct(private string $defaultCheckoutUrl) {}

    public function build(?string $template, string $planKey, string $currency, string $interval, int $priceMinor): string
    {
        $target = $this->firstNonEmpty($template, $this->defaultCheckoutUrl);

        $replacements = [
            '{plan}' => $planKey,
            '{currency}' => $currency,
            '{interval}' => $interval,
            '{price}' => (string) $priceMinor,
        ];

        if ($this->hasPlaceholder($target)) {
            return strtr($target, array_map(rawurlencode(...), $replacements));
        }

        return $this->appendQuery($target, [
            'plan' => $planKey,
            'currency' => $currency,
            'interval' => $interval,
            'price' => (string) $priceMinor,
        ]);
    }

    private function hasPlaceholder(string $target): bool
    {
        return str_contains($target, '{plan}')
            || str_contains($target, '{currency}')
            || str_contains($target, '{interval}')
            || str_contains($target, '{price}');
    }

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $target, array $params): string
    {
        $separator = str_contains($target, '?') ? '&' : '?';

        return $target.$separator.http_build_query($params);
    }

    private function firstNonEmpty(?string $preferred, string $fallback): string
    {
        return $preferred !== null && trim($preferred) !== '' ? $preferred : $fallback;
    }
}
