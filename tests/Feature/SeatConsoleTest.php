<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CboxIdAccessGrant;
use App\Models\SeatAssignment;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The provider console's Seats panel and its guardrailed buy/assign/unassign actions on the
 * subscription-detail screen. Purchased Full seats are the billed quantity; assignment moves
 * a member between Full (billed) and Light (free) without touching the billed quantity.
 */
class SeatConsoleTest extends TestCase
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

    private function servingSubscription(string $org): Subscription
    {
        return Subscription::query()->where('organization_id', $org)->serving()->firstOrFail();
    }

    public function test_the_seats_panel_shows_purchased_full_and_light_totals(): void
    {
        $subscription = $this->servingSubscription('org_hverdag');

        $this->withSession($this->session)->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertSee('Seats')
            ->assertSee('Purchased seats')
            ->assertSee('Full')
            ->assertSee('Light')
            // The seeded org has assigned (Full) and unassigned-eligible (Light) members.
            ->assertSee('org_hverdag_member_1');
    }

    public function test_assigning_and_unassigning_a_seat_moves_a_member_between_full_and_light(): void
    {
        $subscription = $this->servingSubscription('org_hverdag');

        // A seeded eligible-but-unassigned (Light) member — the org has free purchased seats.
        $light = CboxIdAccessGrant::query()
            ->where('organization_id', 'org_hverdag')
            ->whereNotIn('subject', SeatAssignment::query()->where('organization_id', 'org_hverdag')->pluck('subject'))
            ->firstOrFail();

        $this->withSession($this->session)
            ->post('/subscriptions/'.$subscription->id.'/seats/assign', ['subject' => $light->subject])
            ->assertRedirect('/subscriptions/'.$subscription->id);

        $this->assertTrue(SeatAssignment::query()->where('organization_id', 'org_hverdag')->where('subject', $light->subject)->exists());

        $this->withSession($this->session)
            ->post('/subscriptions/'.$subscription->id.'/seats/unassign', ['subject' => $light->subject])
            ->assertRedirect('/subscriptions/'.$subscription->id);

        $this->assertFalse(SeatAssignment::query()->where('organization_id', 'org_hverdag')->where('subject', $light->subject)->exists());
    }

    public function test_buying_seats_raises_the_purchased_count_from_the_console(): void
    {
        $subscription = $this->servingSubscription('org_hverdag');
        $target = $subscription->seats + 2;

        $this->withSession($this->session)
            ->post('/subscriptions/'.$subscription->id.'/seats', ['seats' => $target])
            ->assertRedirect('/subscriptions/'.$subscription->id)
            ->assertSessionHas('status');

        $this->assertSame($target, $subscription->refresh()->seats);
    }

    public function test_releasing_below_the_assigned_count_is_refused_with_an_error_flash(): void
    {
        $subscription = $this->servingSubscription('org_hverdag');
        $assigned = SeatAssignment::query()->where('organization_id', 'org_hverdag')->count();
        $this->assertGreaterThan(0, $assigned);

        // Try to drop purchased below the assigned count — refused, quantity unchanged.
        $this->withSession($this->session)
            ->post('/subscriptions/'.$subscription->id.'/seats', ['seats' => $assigned - 1])
            ->assertRedirect('/subscriptions/'.$subscription->id)
            ->assertSessionHas('error');

        $this->assertSame($subscription->seats, $subscription->refresh()->seats);
    }
}
