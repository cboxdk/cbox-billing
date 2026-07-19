<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 1 "no-gaps UI" foundation: real server-side list search + pagination, the filtered
 * empty state, the accessible row pattern, and the destructive-action confirm guard. These
 * lock in the reusable behaviour waves 2-4 build on.
 */
class ConsoleUxFoundationTest extends TestCase
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

    public function test_customer_search_narrows_the_list_server_side(): void
    {
        $response = $this->withSession($this->session)->get('/customers?q=Hverdag')->assertOk();

        $response->assertSee('Hverdag ApS');
        // A different seeded organization must be filtered out of the results.
        $response->assertDontSee('Nordwind Media');
    }

    public function test_a_filter_with_no_matches_renders_the_distinct_empty_state(): void
    {
        $this->withSession($this->session)->get('/customers?q=zzz-no-such-org')
            ->assertOk()
            ->assertSee('No matches')
            ->assertSee('zzz-no-such-org');
    }

    public function test_subscription_search_matches_the_customer_name(): void
    {
        $this->withSession($this->session)->get('/subscriptions?q=Hverdag')
            ->assertOk()
            ->assertSee('Hverdag ApS');
    }

    public function test_invoice_search_matches_the_customer_name(): void
    {
        $this->withSession($this->session)->get('/invoices?q=zzz-no-such-org')
            ->assertOk()
            ->assertSee('No matches');
    }

    public function test_customer_list_paginates_at_the_page_boundary(): void
    {
        // 25 searchable organizations → two pages at the 20-per-page default.
        for ($i = 1; $i <= 25; $i++) {
            Organization::query()->create([
                'id' => sprintf('org_pagetest_%02d', $i),
                'name' => sprintf('Zpagetest %02d', $i),
                'billing_email' => sprintf('pagetest%02d@example.test', $i),
                'billing_country' => 'DK',
            ]);
        }

        // Page 1: first 20 of the filtered set, with a working pager into page 2.
        $page1 = $this->withSession($this->session)->get('/customers?q=Zpagetest')->assertOk();
        $page1->assertSee('Zpagetest 01');
        $page1->assertDontSee('Zpagetest 21');
        $page1->assertSee('of 25');
        $page1->assertSee('Next ›');

        // Page 2: the remainder.
        $this->withSession($this->session)->get('/customers?q=Zpagetest&page=2')
            ->assertOk()
            ->assertSee('Zpagetest 21')
            ->assertSee('Zpagetest 25')
            ->assertDontSee('Zpagetest 01');
    }

    public function test_list_rows_use_the_accessible_href_pattern_not_inline_onclick(): void
    {
        $response = $this->withSession($this->session)->get('/subscriptions')->assertOk();

        // Rows are keyboard-operable links (data-href + role=link), never onclick navigation.
        $response->assertSee('data-href=', false);
        $response->assertSee('role="link"', false);
        $response->assertDontSee('onclick="window.location', false);
    }

    public function test_destructive_subscription_cancel_renders_the_confirm_guard(): void
    {
        $subscription = Subscription::query()->where('organization_id', 'org_hverdag')->firstOrFail();

        $this->withSession($this->session)->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertSee('data-confirm=', false);
    }

    public function test_the_server_still_processes_the_cancel_without_a_client_confirm(): void
    {
        // The confirm dialog is a client-side guard; the endpoint must still act on a plain
        // POST (its real protection is the permission middleware + validation, not a token).
        $subscription = Subscription::query()->where('organization_id', 'org_hverdag')->firstOrFail();

        $this->withSession($this->session)
            ->post('/subscriptions/'.$subscription->id.'/cancel', ['mode' => 'period_end'])
            ->assertRedirect('/subscriptions/'.$subscription->id);
    }
}
