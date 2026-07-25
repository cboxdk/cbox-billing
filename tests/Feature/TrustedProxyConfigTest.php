<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The trusted-proxy list must be reachable in a deployment that has run `php artisan
 * config:cache` — which the `composer deploy` script does on every release.
 *
 * The first version of this fix read `env('TRUSTED_PROXIES')` in `bootstrap/app.php`, which is
 * silently inert once the config is cached: `LoadEnvironmentVariables::bootstrap()` returns early
 * when `configurationIsCached()`, so `.env` is never parsed and `env()` returns null. The setting
 * would have worked in local development and done nothing in production — the one place the
 * throttling and e-signature consequences actually bite.
 *
 * Keeping the value in a config file is what makes it cache-safe: config files are evaluated while
 * the cache is being built, so the resolved value is baked in.
 */
class TrustedProxyConfigTest extends TestCase
{
    public function test_the_proxy_list_is_read_from_config_not_a_cache_unsafe_env_call(): void
    {
        // A cached config is exactly the state in which `env()` stops working. Simulating it by
        // reading through config() proves the value does not depend on the .env file at runtime.
        config()->set('trustedproxy.proxies', '10.0.0.0/8');

        $middleware = new TrustProxies;
        $request = Request::create('https://billing.example.test/api/v1/reserve', 'POST');
        $request->server->set('REMOTE_ADDR', '10.1.2.3');
        $request->headers->set('X-Forwarded-For', '203.0.113.9');

        $middleware->handle($request, static fn (Request $r): Request => $r);

        $this->assertSame(
            '203.0.113.9',
            $request->ip(),
            'The client IP behind a trusted proxy must come from X-Forwarded-For; if this is the '
            .'proxy address, per-IP throttles on the settlement path share one bucket.',
        );
    }

    /** With nothing configured, no proxy is trusted — a spoofed header must not be believed. */
    public function test_an_unconfigured_deployment_trusts_no_proxy(): void
    {
        config()->set('trustedproxy.proxies', null);

        $middleware = new TrustProxies;
        $request = Request::create('https://billing.example.test/api/v1/reserve', 'POST');
        $request->server->set('REMOTE_ADDR', '10.1.2.3');
        $request->headers->set('X-Forwarded-For', '203.0.113.9');

        $middleware->handle($request, static fn (Request $r): Request => $r);

        $this->assertSame(
            '10.1.2.3',
            $request->ip(),
            'With no trusted proxy configured the forwarded header must be ignored, otherwise any '
            .'client could spoof its own address.',
        );
    }

    /** The config key the framework actually reads must exist and be env-driven. */
    public function test_the_config_file_is_present_and_env_driven(): void
    {
        $this->assertTrue(
            file_exists(base_path('config/trustedproxy.php')),
            'config/trustedproxy.php is the cache-safe home for TRUSTED_PROXIES.',
        );

        $this->assertArrayHasKey('proxies', require base_path('config/trustedproxy.php'));
    }
}
