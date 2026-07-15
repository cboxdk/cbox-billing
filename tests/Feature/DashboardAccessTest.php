<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    /**
     * An authenticated session reaches the dashboard shell (200) — proving the auth
     * gate and the dashboard route remain intact alongside the billing wiring.
     */
    public function test_authenticated_user_sees_the_dashboard(): void
    {
        $response = $this->withSession([
            'auth.user' => [
                'sub' => 'demo|tester',
                'name' => 'Test Operator',
                'email' => 'ops@example.test',
                'org' => 'Cbox Systems',
                'picture' => null,
            ],
        ])->get('/');

        $response->assertOk();
    }

    /** The sign-in screen renders for a guest. */
    public function test_login_screen_renders(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
