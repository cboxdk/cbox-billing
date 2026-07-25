<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Contracts\IdentityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /auth/demo` mints a full console session with no credential at all. It was gated only on
 * "is the identity provider configured", which is true only while ALL of issuer/client-id/redirect
 * are set — so a production deploy that lost one of them (a rotation, a bad secrets mount, a
 * config-cache miss) silently re-enabled an unauthenticated login. The second gate, EnsureOperator,
 * would normally deny the demo org — except that allowlisting exactly that org is what
 * `.env.example` tells developers to do locally, and `.env` files get copied.
 */
class DemoSignInTest extends TestCase
{
    use RefreshDatabase;

    private function withoutIdp(): void
    {
        // The provider is built from `services.cbox_id.*`, not the `cbox-id-client` package config.
        config()->set('services.cbox_id.issuer', null);
        config()->set('services.cbox_id.client_id', null);
        config()->set('services.cbox_id.redirect', null);

        // The client receives its config array at construction, so a container instance resolved
        // earlier in the boot would still hold the old values.
        $this->app->forgetInstance(IdentityProvider::class);
    }

    public function test_demo_sign_in_is_available_locally_when_no_idp_is_configured(): void
    {
        $this->withoutIdp();

        $this->post('/auth/demo')->assertRedirect();
    }

    public function test_demo_sign_in_is_refused_in_production_even_with_no_idp_configured(): void
    {
        $this->withoutIdp();
        app()->detectEnvironment(static fn (): string => 'production');

        // Forcing the environment also re-arms CSRF — the middleware skips only while the app
        // reports the `testing` environment, and `isProduction()` reads that same value. Middleware
        // is disabled for this request so the 419 cannot mask the 404 being asserted; the guard
        // under test lives in the controller, not the middleware stack.
        $this->withoutMiddleware();

        $this->post('/auth/demo')->assertNotFound();
    }
}
