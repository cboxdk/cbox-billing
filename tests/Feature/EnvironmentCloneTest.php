<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\EnvironmentType;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Billing\Environments\GatewayKeyMode;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PricingTable;
use App\Models\SellerEntity;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Database\Seeders\PricingTableSeeder;
use Database\Seeders\SellerEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Env Wave 2 (config env-scoping + clone). Production owns the seeded config; a clone is a
 * deep, ISOLATED copy of that config into a fresh sandbox plane — same catalog/branding/
 * templates/storefront, an empty book, and test gateway keys — with every intra-config
 * relationship preserved and NOTHING shared back to production.
 */
class EnvironmentCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The environment planes (production/sandbox) + the production plane's config: catalog,
        // seller/branding, storefront, + a mail template.
        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class, SellerEntitySeeder::class, PricingTableSeeder::class]);

        $seller = SellerEntity::query()->firstOrFail();
        MailTemplate::query()->create([
            'event_type' => 'invoice.finalized',
            'locale' => 'en',
            'seller_entity_id' => $seller->getKey(),
            'subject' => 'Your invoice',
            'body' => 'Hello {{ name }}',
        ]);
    }

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    private function cloner(): ClonesEnvironments
    {
        return app(ClonesEnvironments::class);
    }

    private function production(): Environment
    {
        return Environment::query()->where('key', Environment::PRODUCTION)->firstOrFail();
    }

    /** Run `$callback` with the ambient plane set to `$key`, restoring production after. */
    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return $this->context()->runInEnvironment($environment, $callback);
    }

    public function test_clone_deep_copies_the_config_surface_isolated_from_production(): void
    {
        $target = $this->cloner()->clone($this->production(), 'acme-test', 'Acme Test');

        // The new plane is a non-protected sandbox on TEST gateway keys (no live secret can leak).
        $this->assertSame(EnvironmentType::Sandbox, $target->type);
        $this->assertFalse($target->protected);
        $this->assertSame(GatewayKeyMode::Test, $target->gateway_key_mode);

        // A plan + its prices, branding, a mail template and a pricing table all exist in the clone.
        $this->inEnvironment('acme-test', function (): void {
            $plan = Plan::query()->where('key', 'starter')->first();
            $this->assertNotNull($plan, 'the cloned plane has its own starter plan');
            $this->assertGreaterThan(0, $plan->prices()->count(), 'the plan carries its per-currency prices');
            $this->assertGreaterThan(0, SellerEntity::query()->count(), 'branding/seller copied');
            $this->assertGreaterThan(0, MailTemplate::query()->count(), 'mail template copied');
            $this->assertGreaterThan(0, PricingTable::query()->count(), 'storefront copied');

            // The cloned plan points at a cloned price of the SAME plane — the relationship holds.
            $price = $plan->prices()->first();
            $this->assertInstanceOf(PlanPrice::class, $price);
            $this->assertSame($plan->getKey(), $price->plan_id);
        });

        // Counts match production exactly (a faithful copy), but the rows are distinct.
        $prodPlanIds = $this->inEnvironment(Environment::PRODUCTION, fn (): array => Plan::query()->pluck('id')->all());
        $clonePlanIds = $this->inEnvironment('acme-test', fn (): array => Plan::query()->pluck('id')->all());
        $this->assertSameSize($prodPlanIds, $clonePlanIds);
        $this->assertEmpty(array_intersect($prodPlanIds, $clonePlanIds), 'clone rows are new, not shared');
    }

    public function test_clone_starts_with_an_empty_book(): void
    {
        // A tenant/organization in production — transactional data the clone must NOT copy.
        Organization::query()->withoutGlobalScopes()->create(['id' => 'org_book', 'name' => 'Book', 'billing_country' => 'DK']);

        $this->cloner()->clone($this->production(), 'acme-test');

        $this->inEnvironment('acme-test', function (): void {
            $this->assertSame(0, Subscription::query()->count(), 'no subscriptions in the clone');
            $this->assertSame(0, Invoice::query()->count(), 'no invoices in the clone');
            $this->assertSame(0, Organization::query()->count(), 'no tenant/customer data copied');
        });

        // Production still owns its tenant data.
        $this->inEnvironment(Environment::PRODUCTION, function (): void {
            $this->assertSame(1, Organization::query()->count());
        });
    }

    public function test_editing_a_cloned_plan_does_not_touch_production(): void
    {
        $this->cloner()->clone($this->production(), 'acme-test');

        $this->inEnvironment('acme-test', function (): void {
            $plan = Plan::query()->where('key', 'starter')->firstOrFail();
            $plan->update(['name' => 'Starter (sandbox edit)']);
        });

        $productionName = $this->inEnvironment(
            Environment::PRODUCTION,
            fn (): string => Plan::query()->where('key', 'starter')->firstOrFail()->name,
        );

        $this->assertNotSame('Starter (sandbox edit)', $productionName);
    }

    public function test_two_environments_can_each_own_the_same_plan_key(): void
    {
        $this->cloner()->clone($this->production(), 'acme-test');

        // Same natural key resolves in BOTH planes — the per-environment unique makes this legal.
        $prod = $this->inEnvironment(Environment::PRODUCTION, fn (): ?Plan => Plan::query()->where('key', 'starter')->first());
        $clone = $this->inEnvironment('acme-test', fn (): ?Plan => Plan::query()->where('key', 'starter')->first());

        $this->assertNotNull($prod);
        $this->assertNotNull($clone);
        $this->assertNotSame($prod->getKey(), $clone->getKey(), 'they are distinct rows sharing one key');
    }

    public function test_a_reclone_reserved_or_invalid_key_is_refused(): void
    {
        $this->cloner()->clone($this->production(), 'acme-test');

        $this->expectException(EnvironmentCloneException::class);
        $this->cloner()->clone($this->production(), 'acme-test'); // key already taken
    }

    public function test_reserved_production_key_is_refused(): void
    {
        $this->expectException(EnvironmentCloneException::class);
        $this->cloner()->clone($this->production(), Environment::PRODUCTION);
    }

    public function test_invalid_key_is_refused(): void
    {
        $this->expectException(EnvironmentCloneException::class);
        $this->cloner()->clone($this->production(), 'Not A Key!');
    }

    public function test_the_clone_command_creates_the_sandbox(): void
    {
        $this->artisan('environment:clone', ['source' => 'production', 'newKey' => 'cli-test', '--name' => 'CLI Test'])
            ->assertSuccessful();

        $target = Environment::query()->where('key', 'cli-test')->first();
        $this->assertNotNull($target);
        $this->assertSame(EnvironmentType::Sandbox, $target->type);

        $this->inEnvironment('cli-test', function (): void {
            $this->assertGreaterThan(0, Plan::query()->count());
        });
    }

    public function test_the_clone_command_refuses_an_unknown_source(): void
    {
        $this->artisan('environment:clone', ['source' => 'no-such-env', 'newKey' => 'x-test'])
            ->assertFailed();
    }
}
