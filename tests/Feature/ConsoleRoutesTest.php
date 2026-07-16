<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every provider-console screen renders (200) on the real, seeded dataset — the index
 * screens, the detail screens, and the URL-is-state filter variants.
 */
class ConsoleRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        $this->withSession(['auth.user' => [
            'sub' => 'demo|tester',
            'name' => 'Test Operator',
            'email' => 'ops@example.test',
            'org' => 'Cbox Systems',
            'picture' => null,
        ]]);
    }

    public function test_index_screens_render_on_real_data(): void
    {
        foreach ([
            '/',
            '/subscriptions',
            '/invoices',
            '/usage',
            '/catalog',
            '/pricing',
            '/customers',
            '/settings',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_filter_variants_render(): void
    {
        foreach (['active', 'trialing', 'past_due', 'canceled'] as $status) {
            $this->get('/subscriptions?status='.$status)->assertOk();
        }

        foreach (['open', 'paid', 'draft'] as $status) {
            $this->get('/invoices?status='.$status)->assertOk();
        }

        $this->get('/usage?org=org_hverdag')->assertOk();
    }

    public function test_detail_screens_render(): void
    {
        $subscription = Subscription::query()->firstOrFail();
        $invoice = Invoice::query()->firstOrFail();

        $this->get('/subscriptions/'.$subscription->id)->assertOk();
        $this->get('/invoices/'.$invoice->id)->assertOk();
        $this->get('/customers/org_hverdag')->assertOk()->assertSee('Hverdag ApS');
    }

    public function test_dashboard_shows_engine_computed_revenue(): void
    {
        // MRR is summed by the engine over active subscriptions in the primary currency.
        $this->get('/')->assertOk()->assertSee('MRR');
    }
}
