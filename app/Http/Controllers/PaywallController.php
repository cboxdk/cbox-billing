<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Notifications\Branding\BrandingResolver;
use App\Billing\Storefront\PaywallPresenter;
use App\Billing\Storefront\ReturnUrlPolicy;
use App\Billing\Storefront\ValueObjects\RenderedPaywall;
use App\Models\BillingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The hosted paywall page (#57): a self-contained, branded "upgrade to unlock" panel an app
 * redirects a blocked user to. It is addressed by the org plus the gated capability
 * (`?org=…&feature=…` or `?org=…&meter=…`) and REUSES the {@see PaywallPresenter} — which in
 * turn reuses the {@see App\Billing\Enforcement\Upgrade\UpgradeGate} — for the required plan.
 *
 * PUBLIC + UNAUTHENTICATED, so it is deliberately side-effect-free: it does NOT mint a
 * `BillingSession` for the query's arbitrary org (that would disclose org existence
 * cross-tenant and spawn unbounded rows). The upgrade CTA links to an EXISTING checkout
 * session only when the caller passes a valid `?session=` token it already holds through an
 * authorized path; otherwise the panel shows a generic offer with no deep-link. The
 * caller-supplied `return_url` is allow-listed to the seller's known/branding hosts
 * ({@see ReturnUrlPolicy}) so the "maybe later" CTA cannot be turned into an open redirect.
 *
 * Deny-by-default: with no reachable upgrade path the page still renders, stating the honest
 * "no upgrade available" outcome rather than a fabricated offer. Thin: validate, delegate, render.
 */
class PaywallController extends Controller
{
    public function show(
        Request $request,
        PaywallPresenter $presenter,
        ReturnUrlPolicy $returnUrls,
        BrandingResolver $branding,
        ManagesBillingSessions $sessions,
        BillingContext $context,
    ): Response {
        $sellerBranding = $branding->forSeller(null);

        $request->validate([
            'org' => ['required', 'string', 'max:255'],
            'feature' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'session' => ['sometimes', 'nullable', 'string', 'max:255'],
            'return_url' => [
                'sometimes', 'nullable', 'url', 'max:2048',
                function (string $attribute, mixed $value, callable $fail) use ($returnUrls, $sellerBranding): void {
                    if (is_string($value) && ! $returnUrls->allows($value, $sellerBranding)) {
                        $fail('The return URL must point to an approved domain.');
                    }
                },
            ],
        ]);

        $org = $request->string('org')->toString();
        // HP1: bootstrap the plane from the (unscoped) checkout session token BEFORE the presenter
        // reaches any mode-scoped org / subscription / entitlement data — a test session must never
        // render live upgrade state. The org is verified against the token, deny-by-default.
        $checkoutUrl = $this->existingCheckoutUrl($request, $sessions, $context, $org);

        $paywall = $request->filled('meter')
            ? $presenter->forMeter($org, $request->string('meter')->toString(), $checkoutUrl)
            : $presenter->forFeature($org, $request->string('feature')->toString(), $checkoutUrl);

        return new Response(
            view('storefront.paywall', [
                'paywall' => $paywall,
                'returnUrl' => $this->returnUrl($request, $paywall),
            ])->render(),
            SymfonyResponse::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * The deep-link for the CTA: an EXISTING, still-usable checkout session the caller already
     * holds (possession of the token is its authorization) — resolved UNSCOPED, never minted. When
     * it resolves and belongs to the requested org, its `livemode` sets the request's plane so the
     * presenter renders that plane's upgrade state; the returned URL is the checkout deep-link.
     * Null (and no plane change — the public default LIVE plane stands) when no token is supplied,
     * it does not resolve to a usable session, or it belongs to a different org (deny-by-default).
     */
    private function existingCheckoutUrl(Request $request, ManagesBillingSessions $sessions, BillingContext $context, string $org): ?string
    {
        if (! $request->filled('session')) {
            return null;
        }

        $token = $request->string('session')->toString();
        // `locate()` resolves unscoped (the token names its own plane); the session must exist, be
        // usable, and belong to the org the paywall is being rendered for.
        $session = $sessions->locate($token, SessionType::Checkout);

        if (! $session instanceof BillingSession || ! $session->isUsable() || $session->organization_id !== $org) {
            return null;
        }

        $context->setMode(BillingMode::fromLivemode($session->livemode));

        return route('hosted.checkout.show', $token);
    }

    /** The "maybe later" destination: the (allow-listed) caller return URL, else the branding support URL. */
    private function returnUrl(Request $request, RenderedPaywall $paywall): ?string
    {
        if ($request->filled('return_url')) {
            return $request->string('return_url')->toString();
        }

        return $paywall->branding->supportUrl;
    }
}
