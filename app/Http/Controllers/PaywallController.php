<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Storefront\PaywallPresenter;
use App\Billing\Storefront\ValueObjects\RenderedPaywall;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The hosted paywall page (#57): a self-contained, branded "upgrade to unlock" panel an app
 * redirects a blocked user to. It is addressed by the org plus the gated capability
 * (`?org=…&feature=…` or `?org=…&meter=…`) and REUSES the {@see PaywallPresenter} — which in
 * turn reuses the {@see App\Billing\Enforcement\Upgrade\UpgradeGate} — so the required plan and
 * the hosted-checkout deep-link are the gate's own output, never recomputed here.
 *
 * Deny-by-default: with no reachable upgrade path the page still renders, stating the honest
 * "no upgrade available" outcome rather than a fabricated offer. Thin: validate, delegate, render.
 */
class PaywallController extends Controller
{
    public function show(Request $request, PaywallPresenter $presenter): Response
    {
        $request->validate([
            'org' => ['required', 'string', 'max:255'],
            'feature' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'return_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);

        $org = $request->string('org')->toString();

        $paywall = $request->filled('meter')
            ? $presenter->forMeter($org, $request->string('meter')->toString())
            : $presenter->forFeature($org, $request->string('feature')->toString());

        return new Response(
            view('storefront.paywall', [
                'paywall' => $paywall,
                'returnUrl' => $this->returnUrl($request, $paywall),
            ])->render(),
            SymfonyResponse::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /** The "maybe later" destination: the caller-supplied return URL, else the branding support URL. */
    private function returnUrl(Request $request, RenderedPaywall $paywall): ?string
    {
        if ($request->filled('return_url')) {
            return $request->string('return_url')->toString();
        }

        return $paywall->branding->supportUrl;
    }
}
