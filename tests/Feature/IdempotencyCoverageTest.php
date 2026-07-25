<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every mutating management route must carry the idempotency middleware — or be on the explicit
 * exemption list below with a stated reason.
 *
 * This is the route-enumeration guard pattern the console gate already uses, applied here because
 * the gap was invisible from either side on its own: the TypeScript SDK attaches an
 * `Idempotency-Key` to every non-GET and retries 5xx, while only six routes actually enforced one.
 * On the rest the key was accepted and ignored, so an SDK retry after a 502 was a plain second
 * call — a second license issued, a duplicate payment intent — while the SDK docs promised
 * otherwise. Nothing failed; the protection simply was not there.
 */
class IdempotencyCoverageTest extends TestCase
{
    /**
     * Routes deliberately without the middleware, and why. Each is either free of side effects or
     * carries its own stronger idempotency.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // The enforcement hot path. `reserve`/`commit` are already keyed by a reservation id, and
        // `usage` ingest is convergent by construction (it posts cumulative minus already-posted,
        // so replays converge). These run at request volume, and the middleware costs a claim row
        // plus a write per call — real cost, no benefit.
        'POST api/v1/leases' => 'enforcement hot path; lease grant is keyed and convergent',
        'POST api/v1/usage' => 'enforcement hot path; cumulative ingest is convergent by design',
        'POST api/v1/reserve' => 'enforcement hot path; keyed by reservation id',
        'POST api/v1/commit' => 'enforcement hot path; keyed by reservation id',

        // A pure computation — it returns a quote and writes nothing.
        'POST api/v1/subscriptions/{org}/preview' => 'no side effect; returns a preview',
    ];

    public function test_every_mutating_management_route_enforces_idempotency(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $methods = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);

            if ($methods === []) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (in_array(EnforceIdempotency::class, $middleware, true) || in_array('idempotency', $middleware, true)) {
                continue;
            }

            foreach ($methods as $method) {
                $signature = $method.' '.$uri;

                if (! array_key_exists($signature, self::EXEMPT)) {
                    $unprotected[] = $signature;
                }
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            'These mutating routes accept an Idempotency-Key and silently ignore it, so an SDK '
            ."retry after a 5xx applies the mutation twice:\n  ".implode("\n  ", $unprotected)
            ."\n\nAdd ->middleware('idempotency'), or add the route to self::EXEMPT with a reason.",
        );
    }

    /** The exemption list must not rot — every entry has to still name a real route. */
    public function test_every_exemption_still_matches_a_real_route(): void
    {
        $live = [];

        foreach (Route::getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $live[] = $method.' '.$route->uri();
            }
        }

        foreach (array_keys(self::EXEMPT) as $signature) {
            $this->assertContains(
                $signature,
                $live,
                "Exempted route [{$signature}] no longer exists — remove it from the list.",
            );
        }
    }
}
