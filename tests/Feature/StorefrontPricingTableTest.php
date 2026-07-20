<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Storefront\PricingTablePresenter;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PricingTable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PricingTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PUBLIC embeddable pricing table (#57): the no-auth `/pricing/{key}` page renders the
 * configured plans in order with the featured column flagged, prices in the selected
 * currency/interval (exact minor units), the feature matrix from the plans' grants, and a CTA
 * deep-link; an inactive/unknown key 404s; the page is self-contained (CSP-safe).
 */
class StorefrontPricingTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CatalogSeeder::class, PricingTableSeeder::class]);
    }

    public function test_public_page_renders_plans_in_order_with_featured_column_and_matrix(): void
    {
        $html = $this->get('/pricing/plans')->assertOk()->getContent();
        $this->assertIsString($html);

        // The four columns appear in their configured order.
        $starter = strpos($html, 'Starter');
        $team = strpos($html, 'Team');
        $business = strpos($html, 'Business');
        $scale = strpos($html, 'Scale');
        $this->assertNotFalse($starter);
        $this->assertTrue($starter < $team && $team < $business && $business < $scale, 'Columns render in configured order.');

        // The featured column is flagged (Team) with its badge.
        $this->assertStringContainsString('pt-col--featured', $html);
        $this->assertStringContainsString('Most popular', $html);

        // The comparison matrix is rendered from the catalog features.
        $this->assertStringContainsString('Compare plans', $html);
        $this->assertStringContainsString('Single sign-on', $html);
    }

    public function test_prices_render_in_selected_currency_and_every_toggle_permutation_is_exact(): void
    {
        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        // Server-rendered default: EUR, monthly — Team is priced at 16900 minor.
        $this->assertStringContainsString('EUR 169,00', $html);

        // Every currency/interval permutation is precomputed into the embedded toggle data, exact
        // to the minor unit via MoneyFormatter (the toggles switch entirely client-side).
        $this->assertStringContainsString('DKK 1.240,00', $html);   // Team, DKK, monthly (124000)
        $this->assertStringContainsString('USD 189,00', $html);     // Team, USD, monthly (18900)
        $this->assertStringContainsString('EUR 1.690,00', $html);   // Team, EUR, yearly (169000 = 10x)
    }

    public function test_cta_deep_links_to_checkout_carrying_plan_currency_and_interval(): void
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $table = PricingTable::query()->create([
            'key' => 'checkout-demo',
            'name' => 'Checkout demo',
            'currencies' => ['EUR'],
            'default_currency' => 'EUR',
            'interval_toggle' => false,
            'cta_url_template' => 'https://shop.test/buy?plan={plan}&currency={currency}&interval={interval}',
            'active' => true,
        ]);
        $table->columns()->create(['plan_id' => $team->id, 'sort_order' => 0, 'featured' => true]);

        $html = (string) $this->get('/pricing/checkout-demo')->assertOk()->getContent();

        // The CTA target has the placeholders substituted for the selected plan+currency+interval.
        $this->assertStringContainsString('https://shop.test/buy?plan=team&amp;currency=EUR&amp;interval=month', $html);
    }

    public function test_feature_matrix_reflects_plan_feature_grants_and_config_values(): void
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();
        $sso = Feature::query()->where('key', 'sso')->firstOrFail();
        $maxProjects = Feature::query()->where('key', 'max_projects')->firstOrFail();

        $table = PricingTable::query()->create([
            'key' => 'matrix-demo',
            'name' => 'Matrix demo',
            'default_currency' => 'EUR',
            'active' => true,
        ]);
        $table->columns()->create(['plan_id' => $starter->id, 'sort_order' => 0]);
        $table->columns()->create(['plan_id' => $team->id, 'sort_order' => 1]);
        $table->featureRows()->create(['feature_id' => $sso->id, 'sort_order' => 0]);
        $table->featureRows()->create(['feature_id' => $maxProjects->id, 'sort_order' => 1]);

        $rendered = app(PricingTablePresenter::class)->present($table);

        $ssoRow = $rendered->featureRows[0];
        $this->assertFalse($ssoRow->cell('starter')->granted, 'Starter does not grant SSO.');
        $this->assertTrue($ssoRow->cell('team')->granted, 'Team grants SSO.');

        // The config feature carries its typed value per plan (max_projects: 3 vs 10).
        $projectsRow = $rendered->featureRows[1];
        $this->assertSame('3', $projectsRow->cell('starter')->value);
        $this->assertSame('10', $projectsRow->cell('team')->value);
    }

    public function test_inactive_and_unknown_keys_are_not_found(): void
    {
        PricingTable::query()->create(['key' => 'draft', 'name' => 'Draft', 'active' => false]);

        $this->get('/pricing/draft')->assertNotFound();
        $this->get('/pricing/does-not-exist')->assertNotFound();
    }

    public function test_public_page_is_self_contained_and_csp_safe(): void
    {
        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        // Strip the inert embedded toggle-data JSON so a checkout URL inside it can't trip the check.
        $markup = (string) preg_replace('#<script type="application/json".*?</script>#s', '', $html);

        $this->assertDoesNotMatchRegularExpression('#<link[\s>]#i', $markup, 'No external stylesheet.');
        $this->assertDoesNotMatchRegularExpression('#<script[^>]+\ssrc=#i', $markup, 'No external script.');
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*"https?://#i', $markup, 'No external asset host.');
        $this->assertStringNotContainsString('@import', $markup);
        $this->assertStringNotContainsString('url(http', $markup);
    }

    public function test_embed_and_loader_surfaces(): void
    {
        $embed = $this->get('/pricing/plans/embed')->assertOk();
        $this->assertStringContainsString('Compare plans', (string) $embed->getContent());
        $this->assertStringContainsString('cbox-pricing-height', (string) $embed->getContent());

        $loader = $this->get('/pricing/plans/embed.js')->assertOk();
        $this->assertStringContainsString('application/javascript', (string) $loader->headers->get('Content-Type'));
        $this->assertStringContainsString('/pricing/plans/embed', (string) $loader->getContent());
        $this->assertStringContainsString('cbox-pricing-height', (string) $loader->getContent());

        $this->get('/pricing/missing/embed.js')->assertNotFound();
    }
}
