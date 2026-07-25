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

    /**
     * With a wildcard, Symfony trusts every hop and returns the LEFTMOST forwarded entry — which
     * the client writes. That inverts the fix: instead of one shared rate-limit bucket, a single
     * source rotates through unlimited attacker-chosen buckets, and the IP recorded against a
     * quote e-signature becomes attacker-supplied. Naming the real proxy range makes Symfony walk
     * in from the right and stop at the first untrusted hop.
     */
    public function test_a_named_proxy_range_returns_the_real_client_not_a_spoofed_one(): void
    {
        config()->set('trustedproxy.proxies', '10.0.0.0/8');

        $middleware = new TrustProxies;
        $request = Request::create('https://billing.example.test/api/v1/reserve', 'POST');
        $request->server->set('REMOTE_ADDR', '10.0.0.5');
        // The client forged the first entry; the ingress appended the address it actually saw.
        $request->headers->set('X-Forwarded-For', '1.2.3.4, 203.0.113.9');

        $middleware->handle($request, static fn (Request $r): Request => $r);

        $this->assertSame(
            '203.0.113.9',
            $request->ip(),
            'A client-forged leading X-Forwarded-For entry must not win — that would let one '
            .'source rotate through unlimited rate-limit buckets.',
        );
    }

    /** The shipped default must not be a wildcard. */
    public function test_the_default_proxy_range_is_not_a_wildcard(): void
    {
        $default = (require base_path('config/trustedproxy.php'))['proxies'];

        $this->assertNotSame('*', $default);
        $this->assertStringNotContainsString('0.0.0.0/0', (string) $default);
    }

    /**
     * `X-Forwarded-Host` must NOT be trusted. Nothing calls `trustHosts()`, so trusting it would
     * let a caller set `X-Forwarded-Host: evil.test` and receive an absolute hosted-checkout URL
     * on that host — carrying a valid session token — which the operator then hands to a customer.
     */
    public function test_the_forwarded_host_header_is_not_trusted(): void
    {
        config()->set('trustedproxy.proxies', '10.0.0.0/8');

        $middleware = new TrustProxies;
        $request = Request::create('https://billing.example.test/api/v1/checkout-sessions', 'POST');
        $request->server->set('REMOTE_ADDR', '10.0.0.5');
        $request->headers->set('X-Forwarded-Host', 'evil.test');

        $middleware->handle($request, static fn (Request $r): Request => $r);

        $this->assertSame('billing.example.test', $request->getHost());
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
