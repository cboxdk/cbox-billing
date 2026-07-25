<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The confirm dialog is the last stop before a void, a refund, or a plane reset. It is centred
 * over a dimmed backdrop, so at the moment of decision the operator's eye is nowhere near the
 * strip at the top of the page — and every `data-confirm` body was authored per call site, none
 * of which named the environment. "Void invoice INV-1042? This cannot be undone." read
 * byte-identical in production and in a sandbox.
 *
 * The plane is now rendered structurally by the dialog itself, so every guarded action inherits
 * it. These tests assert it is present and correct in both planes, and that it is absent on the
 * customer-facing portal — where operator plane chrome would be noise, not safety.
 */
class ConfirmDialogPlaneCueTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): void
    {
        config()->set('billing.console.operator_orgs', ['org_ops']);
        config()->set('billing.console.operator_subjects', []);

        $this->withSession(['auth.user' => [
            'sub' => 'demo|user',
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'org' => 'org_ops',
            'picture' => null,
        ]]);
    }

    public function test_the_production_plane_is_named_on_a_destructive_confirm(): void
    {
        $this->operator();

        $response = $this->get('/invoices');

        $response->assertOk();
        $response->assertSee('PRODUCTION — real customers', false);
        $response->assertDontSee('SANDBOX —', false);
    }

    public function test_the_sandbox_plane_is_named_with_its_environment(): void
    {
        $this->operator();

        // The switcher's state lives behind the CurrentUser session seam; the middleware reads it
        // per request, so setting the ambient context here would just be overwritten.
        $response = $this->withSession(['console.environment' => 'sandbox'])->get('/invoices');

        $response->assertOk();
        $response->assertSee('SANDBOX —', false);
        $response->assertDontSee('PRODUCTION — real customers', false);
    }

    /**
     * The same partial is included by the hosted portal layout. A customer must not be shown
     * operator plane chrome, so the row is omitted when no environment is in scope.
     */
    public function test_the_customer_portal_confirm_carries_no_plane_row(): void
    {
        $token = ApiToken::query()->count(); // touch the connection so RefreshDatabase is honoured
        $this->assertSame(0, $token);

        $rendered = view('partials.confirm-dialog')->render();

        $this->assertStringNotContainsString('PRODUCTION — real customers', $rendered);
        $this->assertStringNotContainsString('cbx-confirm-plane', $rendered);
    }
}
