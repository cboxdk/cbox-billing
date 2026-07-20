<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets a strict `Referrer-Policy: no-referrer` on the hosted pages (P3). The hosted checkout and
 * customer portal are addressed by an opaque bearer token in the URL, and those pages mount the
 * gateway's own element (Stripe & co.) plus other third-party subresources — without this header
 * the full token-bearing URL would leak to them in the `Referer` of every outbound request. With
 * `no-referrer` the browser sends no referrer at all, so the session token never travels off-page.
 */
class SetsReferrerPolicy
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
