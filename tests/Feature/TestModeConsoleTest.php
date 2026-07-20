<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TestClock;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console sandbox UX: the persistent test-mode toggle, the test-clock manager (create +
 * advance), and the test-mode banner. Renders on the design-system components.
 */
class TestModeConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_the_toggle_turns_test_mode_on_and_shows_the_banner(): void
    {
        $this->withSession($this->session)
            ->post('/test-mode/toggle', ['enabled' => '1'])
            ->assertRedirect();

        // With the flag on, the dashboard shows the unmistakable sandbox strip.
        $this->withSession(['console.environment' => 'sandbox'] + $this->session)
            ->get('/')
            ->assertOk()
            ->assertSee('TEST MODE');
    }

    public function test_the_test_clock_page_creates_and_advances_a_clock(): void
    {
        $this->withSession($this->session)
            ->get('/test-mode/clocks')
            ->assertOk()
            ->assertSee('Test clocks');

        $this->withSession($this->session)
            ->post('/test-mode/clocks', ['name' => 'Scenario', 'now_at' => '2026-01-01T00:00'])
            ->assertRedirect();

        $clock = TestClock::query()->firstOrFail();
        $this->assertSame('Scenario', $clock->name);

        $this->withSession($this->session)
            ->get(route('billing.test-mode.clocks.show', $clock))
            ->assertOk()
            ->assertSee('Advance the clock');

        $this->withSession($this->session)
            ->post(route('billing.test-mode.clocks.advance', $clock), ['target' => '2026-02-15T00:00'])
            ->assertRedirect();

        // The clock's virtual time moved forward.
        $this->assertSame('2026-02-15', $clock->refresh()->now_at->format('Y-m-d'));
    }
}
