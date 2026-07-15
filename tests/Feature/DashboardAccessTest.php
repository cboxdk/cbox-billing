<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    /**
     * An authenticated session reaches the dashboard shell (200) — proving the auth
     * gate and the dashboard route render on the real, seeded dataset.
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
