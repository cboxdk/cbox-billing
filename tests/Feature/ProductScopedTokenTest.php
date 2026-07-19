<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\AssistantCatalogSeeder;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product isolation on a shared instance: a token bound to one product sees only
 * that product's catalog and cannot subscribe an org to (or check out) another
 * product's plans — deny-by-default across products.
 */
class ProductScopedTokenTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function assistantAuth(): array
    {
        // Both catalogs on one instance: the demo (billing's own) + the assistant's.
        $this->seed(CatalogSeeder::class);
        $this->seed(AssistantCatalogSeeder::class);

        $product = Product::query()->where('key', 'cbox-assistant')->firstOrFail();
        ['plaintext' => $token] = ApiToken::issue('assistant', null, (int) $product->id);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_a_product_bound_token_sees_only_its_own_catalog(): void
    {
        $auth = $this->assistantAuth();

        $keys = collect($this->getJson('/api/v1/plans?currency=USD', $auth)->assertOk()->json('data'))
            ->pluck('key')->all();

        $this->assertSame(['assistant-starter', 'assistant-growth'], $keys);
    }

    public function test_a_product_bound_token_cannot_sell_another_products_plan(): void
    {
        $auth = $this->assistantAuth();
        Organization::query()->create(['id' => 'ws_1', 'name' => 'Ws']);

        // The demo catalog's 'starter' exists and is active — but belongs to cbox-billing.
        $this->assertNotNull(Plan::query()->where('key', 'starter')->first());

        $this->postJson('/api/v1/subscriptions', ['org' => 'ws_1', 'plan' => 'starter'], $auth)
            ->assertNotFound();

        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'ws_1', 'plan' => 'starter', 'return_url' => 'https://app.test/billing/return',
        ], $auth)->assertNotFound();

        // Its own product's plan works.
        $this->postJson('/api/v1/subscriptions', ['org' => 'ws_1', 'plan' => 'assistant-growth'], $auth)
            ->assertCreated();
    }

    public function test_a_product_bound_token_cannot_change_or_preview_onto_another_products_plan(): void
    {
        $auth = $this->assistantAuth();
        // A billing address so a real (paid) plan change can assess tax + charge.
        Organization::query()->create(['id' => 'ws_1', 'name' => 'Ws', 'billing_country' => 'DK']);

        // Start on our own product's plan.
        $this->postJson('/api/v1/subscriptions', ['org' => 'ws_1', 'plan' => 'assistant-starter'], $auth)
            ->assertCreated();

        // Neither change nor preview may retarget the subscription onto another
        // product's plan — the isolation seam must hold on the mutation paths too.
        $this->postJson('/api/v1/subscriptions/ws_1/change', ['plan' => 'starter'], $auth)
            ->assertNotFound();
        $this->postJson('/api/v1/subscriptions/ws_1/preview', ['plan' => 'starter'], $auth)
            ->assertNotFound();

        // ...but our own upgrade path works.
        $this->postJson('/api/v1/subscriptions/ws_1/change', ['plan' => 'assistant-growth'], $auth)
            ->assertOk();
    }

    public function test_an_unbound_token_keeps_the_whole_catalog(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(AssistantCatalogSeeder::class);
        config(['billing.api.static_token' => 'operator']);

        $keys = collect($this->getJson('/api/v1/plans?currency=USD', ['Authorization' => 'Bearer operator'])
            ->assertOk()->json('data'))->pluck('key');

        $this->assertTrue($keys->contains('starter'));
        $this->assertTrue($keys->contains('assistant-starter'));
    }
}
