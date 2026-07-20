<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\ClonesEnvironments;
use App\Billing\Environments\Promotion\Contracts\PromotesConfig;
use App\Billing\Environments\Promotion\Enums\ChangeStatus;
use App\Billing\Environments\Promotion\Exceptions\PromotionException;
use App\Billing\Environments\Promotion\PromotionGroup;
use App\Billing\Environments\Promotion\PromotionObjectRef;
use App\Billing\Environments\Promotion\PromotionSelection;
use App\Billing\Environments\Promotion\ValueObjects\ObjectChange;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\MailTemplate;
use App\Models\OperatorAuditEvent;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SellerEntity;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Database\Seeders\PricingTableSeeder;
use Database\Seeders\SellerEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Env Wave 3 (config promotion). Production owns the seeded config; a sandbox is cloned from it,
 * edited, and then SELECTED config is promoted back — matched across planes by stable natural key,
 * previewed as a created/updated/unchanged diff, applied additively (upsert, never destructive)
 * with relationships remapped to the target plane's ids, idempotently, and audit-logged.
 */
class ConfigPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class, SellerEntitySeeder::class, PricingTableSeeder::class]);

        $seller = SellerEntity::query()->firstOrFail();
        MailTemplate::query()->create([
            'event_type' => 'invoice.finalized',
            'locale' => 'en',
            'seller_entity_id' => $seller->getKey(),
            'subject' => 'Your invoice',
            'body' => 'Hello {{ name }}',
        ]);

        // A disposable sandbox cloned from production — the plane we fiddle with, then promote from.
        $this->cloner()->clone($this->production(), 'sandbox2', 'Sandbox 2');
    }

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    private function cloner(): ClonesEnvironments
    {
        return app(ClonesEnvironments::class);
    }

    private function promoter(): PromotesConfig
    {
        return app(PromotesConfig::class);
    }

    private function production(): Environment
    {
        return Environment::query()->where('key', Environment::PRODUCTION)->firstOrFail();
    }

    private function sandbox2(): Environment
    {
        return Environment::query()->where('key', 'sandbox2')->firstOrFail();
    }

    /** Run `$callback` with the ambient plane set to `$key`. */
    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return $this->context()->runInEnvironment($environment, $callback);
    }

    // -----------------------------------------------------------------------------------------
    // Selective promotion + the diff preview
    // -----------------------------------------------------------------------------------------

    public function test_promoting_only_branding_updates_branding_and_leaves_plan_and_template_untouched(): void
    {
        $sellerId = SellerEntity::query()->firstOrFail()->getKey();

        // Edit three different groups in the sandbox: a plan's price, the branding accent, and a
        // mail-template subject. Only branding will be promoted.
        $this->inEnvironment('sandbox2', function () use ($sellerId): void {
            $plan = Plan::query()->where('key', 'starter')->firstOrFail();
            $plan->prices()->firstOrFail()->update(['price_minor' => 999_99]);

            SellerEntity::query()->whereKey('sandbox2__'.$sellerId)->firstOrFail()->update(['brand_color' => '#ff0055']);

            MailTemplate::query()->firstOrFail()->update(['subject' => 'Sandbox subject']);
        });

        $selection = new PromotionSelection(groups: [PromotionGroup::Branding]);

        // The preview lists EXACTLY the branding change (the seller, updated on brand_color) — no
        // plan and no mail-template change appears, because only the branding group was selected.
        $preview = $this->promoter()->preview($this->sandbox2(), $this->production(), $selection);

        $this->assertFalse($preview->hasConflicts());
        $this->assertSame(1, $preview->updatedCount(), 'exactly one branding object changed');
        $this->assertSame([], array_values(array_filter(
            $preview->changes,
            static fn (ObjectChange $c): bool => $c->type !== 'seller',
        )), 'the branding selection previews sellers only');

        $sellerChange = $this->changeFor($preview->changes, 'seller', $sellerId);
        $this->assertSame(ChangeStatus::Updated, $sellerChange->status);
        $this->assertSame(['brand_color'], array_map(fn ($f) => $f->field, $sellerChange->fieldChanges));

        // Apply.
        $result = $this->promoter()->promote($this->sandbox2(), $this->production(), $selection);
        $this->assertSame(1, $result->updated);
        $this->assertTrue($result->wroteAnything());

        // Production branding updated; its plan price + template are UNCHANGED (selective works).
        $this->inEnvironment(Environment::PRODUCTION, function () use ($sellerId): void {
            $this->assertSame('#ff0055', SellerEntity::query()->whereKey($sellerId)->firstOrFail()->brand_color);
            $this->assertNotSame(999_99, Plan::query()->where('key', 'starter')->firstOrFail()->prices()->firstOrFail()->price_minor);
            $this->assertSame('Your invoice', MailTemplate::query()->firstOrFail()->subject);
        });
    }

    // -----------------------------------------------------------------------------------------
    // A new object created only in the sandbox, promoted with relationships remapped
    // -----------------------------------------------------------------------------------------

    public function test_promoting_a_new_plan_creates_it_in_production_with_prices_and_tiers_remapped(): void
    {
        // A brand-new plan (with a price + tiers) authored only in the sandbox, under the existing
        // product — production has no such plan.
        $this->inEnvironment('sandbox2', function (): void {
            $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();
            $plan = Plan::query()->create([
                'product_id' => $product->getKey(),
                'key' => 'growth',
                'name' => 'Growth',
                'interval' => 'month',
                'active' => true,
            ]);
            $price = $plan->prices()->create([
                'currency' => 'usd',
                'price_minor' => 4900,
                'pricing_model' => 'graduated',
            ]);
            $price->tiers()->create(['up_to' => 10, 'unit_minor' => 500, 'sort_order' => 0]);
            $price->tiers()->create(['up_to' => null, 'unit_minor' => 300, 'sort_order' => 1]);
        });

        $prodProductId = $this->inEnvironment(Environment::PRODUCTION, fn () => Product::query()->where('key', 'cbox-billing')->firstOrFail()->getKey());
        $prodPlanCountBefore = $this->inEnvironment(Environment::PRODUCTION, fn (): int => Plan::query()->count());

        $selection = new PromotionSelection(objects: [new PromotionObjectRef('plan', 'growth')]);
        $result = $this->promoter()->promote($this->sandbox2(), $this->production(), $selection);

        $this->assertSame(1, $result->created);

        $this->inEnvironment(Environment::PRODUCTION, function () use ($prodProductId, $prodPlanCountBefore): void {
            $plan = Plan::query()->where('key', 'growth')->first();
            $this->assertNotNull($plan, 'the new plan was created in production');
            $this->assertSame($prodProductId, $plan->product_id, 'its product FK was remapped to the TARGET product id');

            $price = $plan->prices()->firstOrFail();
            $this->assertSame($plan->getKey(), $price->plan_id, 'the price attaches to the new production plan id');
            $this->assertSame('graduated', $price->pricing_model);

            $tiers = $price->tiers()->orderBy('sort_order')->get();
            $this->assertCount(2, $tiers, 'both tiers came across');
            $this->assertSame($price->getKey(), $tiers->first()?->plan_price_id, 'tiers attach to the new production price id');

            // Nothing else changed: the pre-existing plans are all still there, plus the one new one.
            $this->assertSame($prodPlanCountBefore + 1, Plan::query()->count());
            $this->assertNotSame('Growth', Plan::query()->where('key', 'starter')->firstOrFail()->name);
        });
    }

    // -----------------------------------------------------------------------------------------
    // Idempotency + audit + blocking conflict
    // -----------------------------------------------------------------------------------------

    public function test_re_promoting_an_unchanged_selection_is_an_idempotent_no_op(): void
    {
        $sellerId = SellerEntity::query()->firstOrFail()->getKey();

        $this->inEnvironment('sandbox2', function () use ($sellerId): void {
            SellerEntity::query()->whereKey('sandbox2__'.$sellerId)->firstOrFail()->update(['brand_color' => '#123456']);
        });

        $selection = new PromotionSelection(groups: [PromotionGroup::Branding]);

        $first = $this->promoter()->promote($this->sandbox2(), $this->production(), $selection);
        $this->assertTrue($first->wroteAnything());

        $auditAfterFirst = OperatorAuditEvent::query()->where('action', 'config.promoted')->count();
        $this->assertSame(1, $auditAfterFirst, 'the apply recorded exactly one config.promoted event');

        // Re-promoting the same, now-identical selection writes nothing and logs nothing.
        $second = $this->promoter()->promote($this->sandbox2(), $this->production(), $selection);
        $this->assertFalse($second->wroteAnything());
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame($auditAfterFirst, OperatorAuditEvent::query()->where('action', 'config.promoted')->count(), 'a no-op re-promotion is not audited');
    }

    public function test_the_apply_is_audit_logged_with_the_object_set(): void
    {
        $sellerId = SellerEntity::query()->firstOrFail()->getKey();
        $this->inEnvironment('sandbox2', function () use ($sellerId): void {
            SellerEntity::query()->whereKey('sandbox2__'.$sellerId)->firstOrFail()->update(['brand_color' => '#abcdef']);
        });

        $this->promoter()->promote($this->sandbox2(), $this->production(), new PromotionSelection(groups: [PromotionGroup::Branding]));

        $event = OperatorAuditEvent::query()->where('action', 'config.promoted')->firstOrFail();
        $metadata = $event->metadata;

        $this->assertIsArray($metadata);
        $this->assertSame('sandbox2', $metadata['source'] ?? null);
        $this->assertSame(Environment::PRODUCTION, $metadata['target'] ?? null);
        $this->assertSame('seller:'.$sellerId, $metadata['objects'][0]['object'] ?? null);
    }

    public function test_a_plan_whose_product_is_absent_and_unselected_is_a_blocking_conflict_with_no_half_apply(): void
    {
        // A new product + plan authored only in the sandbox; production has neither.
        $this->inEnvironment('sandbox2', function (): void {
            $product = Product::query()->create(['key' => 'addon', 'name' => 'Add-on']);
            Plan::query()->create([
                'product_id' => $product->getKey(),
                'key' => 'addon-plan',
                'name' => 'Add-on plan',
                'interval' => 'month',
                'active' => true,
            ]);
        });

        // Promote ONLY the plan — its product is neither in production nor part of the selection.
        $selection = new PromotionSelection(objects: [new PromotionObjectRef('plan', 'addon-plan')]);

        $preview = $this->promoter()->preview($this->sandbox2(), $this->production(), $selection);
        $this->assertTrue($preview->hasConflicts(), 'the missing product surfaces as a blocking conflict in the preview');

        try {
            $this->promoter()->promote($this->sandbox2(), $this->production(), $selection);
            $this->fail('expected the promotion to be refused');
        } catch (PromotionException $e) {
            $this->assertNotSame([], $e->conflicts);
        }

        // No half-apply: production gained neither the plan nor the product.
        $this->inEnvironment(Environment::PRODUCTION, function (): void {
            $this->assertNull(Plan::query()->where('key', 'addon-plan')->first());
            $this->assertNull(Product::query()->where('key', 'addon')->first());
        });
    }

    public function test_the_promote_command_previews_and_applies(): void
    {
        $sellerId = SellerEntity::query()->firstOrFail()->getKey();
        $this->inEnvironment('sandbox2', function () use ($sellerId): void {
            SellerEntity::query()->whereKey('sandbox2__'.$sellerId)->firstOrFail()->update(['brand_color' => '#0a0a0a']);
        });

        // Dry-run prints the diff and writes nothing.
        $this->artisan('environment:promote', ['source' => 'sandbox2', 'target' => 'production', '--only' => 'branding', '--dry-run' => true])
            ->assertSuccessful();
        $this->inEnvironment(Environment::PRODUCTION, function () use ($sellerId): void {
            $this->assertNotSame('#0a0a0a', SellerEntity::query()->whereKey($sellerId)->firstOrFail()->brand_color);
        });

        // Without --dry-run it applies.
        $this->artisan('environment:promote', ['source' => 'sandbox2', 'target' => 'production', '--only' => 'branding'])
            ->assertSuccessful();
        $this->inEnvironment(Environment::PRODUCTION, function () use ($sellerId): void {
            $this->assertSame('#0a0a0a', SellerEntity::query()->whereKey($sellerId)->firstOrFail()->brand_color);
        });
    }

    // -----------------------------------------------------------------------------------------
    // The console "Promote" screen
    // -----------------------------------------------------------------------------------------

    /** @var array<string, mixed> */
    private array $operatorSession = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_promote_screen_renders_and_previews_the_diff(): void
    {
        $sellerId = SellerEntity::query()->firstOrFail()->getKey();
        $this->inEnvironment('sandbox2', function () use ($sellerId): void {
            SellerEntity::query()->whereKey('sandbox2__'.$sellerId)->firstOrFail()->update(['brand_color' => '#654321']);
        });

        $this->withSession($this->operatorSession)->get('/environment/promote')
            ->assertOk()
            ->assertSee('Promote config')
            ->assertSee('What to promote')
            ->assertSee('Preview changes');

        $this->withSession($this->operatorSession)
            ->post('/environment/promote/preview', ['source' => 'sandbox2', 'target' => 'production', 'groups' => ['branding']])
            ->assertOk()
            ->assertSee('Diff preview')
            ->assertSee('brand_color')
            ->assertSee('Publish to production');
    }

    public function test_the_promote_screen_publishes_on_confirm(): void
    {
        $sellerId = SellerEntity::query()->firstOrFail()->getKey();
        $this->inEnvironment('sandbox2', function () use ($sellerId): void {
            SellerEntity::query()->whereKey('sandbox2__'.$sellerId)->firstOrFail()->update(['brand_color' => '#777777']);
        });

        $this->withSession($this->operatorSession)
            ->post('/environment/promote', ['source' => 'sandbox2', 'target' => 'production', 'groups' => ['branding']])
            ->assertRedirect(route('billing.environment.promote'))
            ->assertSessionHas('status');

        $this->inEnvironment(Environment::PRODUCTION, function () use ($sellerId): void {
            $this->assertSame('#777777', SellerEntity::query()->whereKey($sellerId)->firstOrFail()->brand_color);
        });
    }

    /**
     * @param  list<ObjectChange>  $changes
     */
    private function changeFor(array $changes, string $type, string $naturalKey): ObjectChange
    {
        foreach ($changes as $change) {
            if ($change->type === $type && $change->naturalKey === $naturalKey) {
                return $change;
            }
        }

        $this->fail("no {$type} change for “{$naturalKey}” in the preview");
    }
}
