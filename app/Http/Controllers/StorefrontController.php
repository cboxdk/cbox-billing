<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Experiments\StorefrontExperimentResolver;
use App\Billing\Experiments\VisitorIdentity;
use App\Billing\Storefront\PricingTablePresenter;
use App\Models\PricingTable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The PUBLIC, no-auth embeddable pricing table (#57). Three surfaces, all served from a
 * fully self-contained (inline CSS/JS, no external hosts — CSP-safe like `/api/docs`),
 * seller-branded, theme-aware page addressed by the table's public `key`:
 *
 *  - `GET /pricing/{key}`          — the standalone, marketing-grade pricing page.
 *  - `GET /pricing/{key}/embed`    — the same table trimmed for iframe embedding (transparent,
 *                                    no page chrome, reports its height to the host frame).
 *  - `GET /pricing/{key}/embed.js` — a tiny self-contained loader an operator drops into their
 *                                    marketing site; it injects the iframe and auto-sizes it.
 *
 * Deny-by-default: an unknown or inactive key 404s (it never leaks that a draft table exists).
 * Thin: resolve the table, project it through {@see PricingTablePresenter}, render.
 */
class StorefrontController extends Controller
{
    public function show(
        string $key,
        Request $request,
        PricingTablePresenter $presenter,
        StorefrontExperimentResolver $experiments,
        VisitorIdentity $visitors,
    ): Response {
        return $this->serve($key, 'page', $request, $presenter, $experiments, $visitors);
    }

    public function embed(
        string $key,
        Request $request,
        PricingTablePresenter $presenter,
        StorefrontExperimentResolver $experiments,
        VisitorIdentity $visitors,
    ): Response {
        return $this->serve($key, 'embed', $request, $presenter, $experiments, $visitors);
    }

    /**
     * Serve the public pricing page, applying any running/promoted A/B experiment: resolve the
     * anonymous visitor, let the experiment resolver pick the table to present (recording an
     * impression + threading the assigned variant's attribution onto the CTA links when the
     * experiment is running), and persist the visitor cookie on the response.
     */
    private function serve(
        string $key,
        string $mode,
        Request $request,
        PricingTablePresenter $presenter,
        StorefrontExperimentResolver $experiments,
        VisitorIdentity $visitors,
    ): Response {
        $base = $this->resolve($key);
        $visitorId = $visitors->resolve($request);

        $served = $experiments->resolve($base, $visitorId);

        $response = $this->html(view('storefront.table', [
            'table' => $presenter->present($served->table, $served->attribution($visitorId)),
            'mode' => $mode,
        ])->render());

        return $response->withCookie($visitors->cookie($visitorId));
    }

    /**
     * The embed loader: a self-contained script that injects `<iframe src=".../embed">` where it
     * is dropped and resizes it to the table's reported height. Served as JavaScript; a strict
     * host CSP need only allow this script's own origin (or use the raw `<iframe>` snippet, which
     * needs no script at all — documented as the CSP-safest option).
     */
    public function loader(string $key, Config $config, UrlGenerator $url): Response
    {
        $this->resolve($key);

        $base = $config->get('billing.storefront.embed_base_url');
        $origin = is_string($base) && $base !== '' ? rtrim($base, '/') : $url->to('/');
        $embedUrl = $origin.'/pricing/'.rawurlencode($key).'/embed';

        return new Response(
            $this->loaderScript($embedUrl, $key),
            SymfonyResponse::HTTP_OK,
            [
                'Content-Type' => 'application/javascript; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }

    private function resolve(string $key): PricingTable
    {
        $table = PricingTable::query()->active()->where('key', $key)->first();

        if (! $table instanceof PricingTable) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $table;
    }

    private function html(string $body): Response
    {
        return new Response($body, SymfonyResponse::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The inline embed loader script. It creates the iframe, and listens for the height messages
     * the embed page posts (scoped to this table's key) so the frame grows to fit with no scrollbar.
     */
    private function loaderScript(string $embedUrl, string $key): string
    {
        $url = json_encode($embedUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tableKey = json_encode($key, JSON_THROW_ON_ERROR);

        return <<<JS
        (function () {
          var src = {$url};
          var key = {$tableKey};
          var current = document.currentScript;
          var frame = document.createElement('iframe');
          frame.src = src;
          frame.title = 'Pricing';
          frame.setAttribute('loading', 'lazy');
          frame.style.width = '100%';
          frame.style.border = '0';
          frame.style.overflow = 'hidden';
          frame.style.minHeight = '480px';
          if (current && current.parentNode) {
            current.parentNode.insertBefore(frame, current);
          } else {
            document.body.appendChild(frame);
          }
          window.addEventListener('message', function (event) {
            var data = event.data;
            if (!data || data.type !== 'cbox-pricing-height' || data.key !== key) return;
            if (typeof data.height === 'number' && data.height > 0) {
              frame.style.height = data.height + 'px';
            }
          });
        })();
        JS;
    }
}
