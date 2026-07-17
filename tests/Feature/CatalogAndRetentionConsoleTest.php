<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engine-v0.8 catalog and lifecycle surfaces render on real data: tiered-pricing tier
 * tables, trial / past-due / paused / non-renewing standings, captured cancellation
 * reasons, the dunning view, and the retention actions.
 */
class CatalogAndRetentionConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    public function test_catalog_shows_a_tiered_prices_tiers(): void
    {
        $response = $this->withSession($this->session)->get('/catalog')->assertOk();

        // Team is priced `graduated`; its DKK tiers include a 99,00 per-seat rate and an
        // unbounded final tier — the engine PriceTier set rendered as an up-to/unit/flat table.
        $response->assertSee('graduated');
        $response->assertSee('DKK 99,00');
        $response->assertSee('Up to');
        // Scale is a package model priced in blocks of 10.
        $response->assertSee('package');
    }

    public function test_subscriptions_list_shows_the_real_lifecycle_standings(): void
    {
        $response = $this->withSession($this->session)->get('/subscriptions')->assertOk();

        $response->assertSee('trial');       // Aula — Trialing
        $response->assertSee('past due');    // Nordwind — PastDue
        $response->assertSee('paused');      // Meridian — paused_at
        $response->assertSee('non-renewing'); // Vinter — cancel_at_period_end
        $response->assertSee('canceled');    // Söder — Canceled
    }

    public function test_subscription_detail_shows_a_captured_cancellation_reason(): void
    {
        $soder = Subscription::query()->where('organization_id', 'org_soder')->firstOrFail();

        $this->withSession($this->session)->get('/subscriptions/'.$soder->id)
            ->assertOk()
            ->assertSee('Cancellation reasons')
            ->assertSee('Too expensive');
    }

    public function test_past_due_subscription_shows_dunning_state(): void
    {
        $nordwind = Subscription::query()->where('organization_id', 'org_nordwind')->firstOrFail();

        $this->withSession($this->session)->get('/subscriptions/'.$nordwind->id)
            ->assertOk()
            ->assertSee('Dunning')
            ->assertSee('retrying');

        $this->withSession($this->session)->get('/subscriptions/dunning')
            ->assertOk()
            ->assertSee('Nordwind Media')
            ->assertSee('retrying');
    }

    public function test_console_cancel_action_captures_a_reason(): void
    {
        $hverdag = Subscription::query()->where('organization_id', 'org_hverdag')->firstOrFail();

        $this->withSession($this->session)
            ->post('/subscriptions/'.$hverdag->id.'/cancel', [
                'mode' => 'period_end',
                'reason' => 'switching_provider',
                'feedback' => 'Consolidating vendors.',
            ])
            ->assertRedirect('/subscriptions/'.$hverdag->id);

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_hverdag',
            'mode' => SubscriptionCancellation::MODE_PERIOD_END,
            'reason' => 'switching_provider',
        ]);
    }

    public function test_console_reactivate_action_resumes_a_paused_subscription(): void
    {
        $meridian = Subscription::query()->where('organization_id', 'org_meridian')->firstOrFail();
        $this->assertTrue($meridian->isPaused());

        $this->withSession($this->session)
            ->post('/subscriptions/'.$meridian->id.'/reactivate')
            ->assertRedirect('/subscriptions/'.$meridian->id);

        $this->assertNull($meridian->refresh()->paused_at);
    }
}
