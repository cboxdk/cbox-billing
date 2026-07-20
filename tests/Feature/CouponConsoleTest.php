<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Coupons\CouponRedeemer;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Coupons console CRUD: create persists + lists, validation refuses bad drafts, edit
 * updates, delete is guarded (a redeemed coupon archives, never hard-deletes), and the
 * write routes are gated by the `catalog:manage` permission.
 */
class CouponConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_index_renders_with_search_empty_state(): void
    {
        $this->coupon('SAVE20');

        $this->withSession($this->session)->get('/coupons')->assertOk()->assertSee('SAVE20');
        $this->withSession($this->session)->get('/coupons?q=zzz-none')->assertOk()->assertSee('No matches');
    }

    public function test_create_persists_and_appears_in_the_list(): void
    {
        $this->withSession($this->session)->post('/coupons', [
            'code' => 'welcome10',
            'name' => 'Welcome',
            'discount_type' => 'percent',
            'percent_off' => 10,
            'duration' => 'once',
            'applies_to' => 'all',
            'active' => '1',
        ])->assertRedirect();

        $coupon = Coupon::query()->where('code', 'WELCOME10')->firstOrFail();
        $this->assertSame(10, $coupon->percent_off);
        $this->withSession($this->session)->get('/coupons')->assertOk()->assertSee('WELCOME10');
    }

    public function test_times_redeemed_is_not_mass_assignable(): void
    {
        // The redemption counter is server-owned; a create/edit must never set it from input.
        $this->withSession($this->session)->post('/coupons', [
            'code' => 'counter',
            'name' => 'Counter',
            'discount_type' => 'percent',
            'percent_off' => 10,
            'duration' => 'once',
            'applies_to' => 'all',
            'active' => '1',
            'times_redeemed' => 999,
        ])->assertRedirect();

        // Persisted at the schema default (0), never the injected 999.
        $this->assertSame(0, Coupon::query()->where('code', 'COUNTER')->firstOrFail()->times_redeemed);

        // Direct mass-assignment on the model is likewise ignored.
        $coupon = Coupon::query()->create([
            'code' => 'DIRECT', 'discount_type' => 'percent', 'percent_off' => 5,
            'duration' => 'once', 'applies_to' => 'all', 'active' => true, 'times_redeemed' => 42,
        ]);
        $this->assertSame(0, $coupon->refresh()->times_redeemed);
    }

    public function test_a_percent_over_100_is_refused(): void
    {
        $this->withSession($this->session)->post('/coupons', [
            'code' => 'TOOMUCH',
            'discount_type' => 'percent',
            'percent_off' => 150,
            'duration' => 'once',
            'applies_to' => 'all',
        ])->assertRedirect()->assertSessionHasErrors('percent_off');

        $this->assertSame(0, Coupon::query()->where('code', 'TOOMUCH')->count());
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->coupon('DUPE');

        $this->withSession($this->session)->post('/coupons', [
            'code' => 'dupe',
            'discount_type' => 'percent',
            'percent_off' => 10,
            'duration' => 'once',
            'applies_to' => 'all',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, Coupon::query()->where('code', 'DUPE')->count());
    }

    public function test_edit_updates(): void
    {
        $coupon = $this->coupon('EDITME', ['percent_off' => 10]);

        $this->withSession($this->session)->put('/coupons/'.$coupon->id, [
            'code' => 'EDITME',
            'discount_type' => 'percent',
            'percent_off' => 30,
            'duration' => 'forever',
            'applies_to' => 'all',
            'active' => '1',
        ])->assertRedirect();

        $coupon->refresh();
        $this->assertSame(30, $coupon->percent_off);
        $this->assertSame('forever', $coupon->duration);
    }

    public function test_a_redeemed_coupon_archives_but_cannot_be_hard_deleted(): void
    {
        $coupon = $this->coupon('USED', ['percent_off' => 10]);
        $this->redeem($coupon);

        // Delete is refused server-side.
        $this->withSession($this->session)->delete('/coupons/'.$coupon->id)
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($coupon->fresh());

        // Archive works and keeps the row.
        $this->withSession($this->session)->post('/coupons/'.$coupon->id.'/archive')->assertRedirect();
        $this->assertNotNull($coupon->fresh()?->archived_at);
    }

    public function test_a_never_redeemed_coupon_hard_deletes(): void
    {
        $coupon = $this->coupon('DRAFT');

        $this->withSession($this->session)->delete('/coupons/'.$coupon->id)->assertRedirect();
        $this->assertNull($coupon->fresh());
    }

    public function test_write_routes_are_gated_by_catalog_manage(): void
    {
        config()->set('billing.rbac.enforce', true);

        // A non-holder is forbidden from the create form.
        $this->signedInWith(['catalog:read'])->get(route('billing.coupons.create'))->assertStatus(403);

        // A holder reaches it.
        $this->signedInWith(['catalog:manage'])->get(route('billing.coupons.create'))->assertOk();
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function coupon(string $code, array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'code' => strtoupper($code),
            'discount_type' => 'percent',
            'percent_off' => 20,
            'duration' => 'once',
            'applies_to' => 'all',
            'active' => true,
        ], $overrides));
    }

    private function redeem(Coupon $coupon): void
    {
        $product = Product::query()->create(['key' => 'app', 'name' => 'App']);
        $plan = Plan::query()->create(['product_id' => $product->id, 'key' => 'p_'.uniqid(), 'name' => 'P', 'interval' => 'month', 'active' => true]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => 10_000]);
        Organization::query()->create(['id' => 'org_used', 'name' => 'Org', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
        $subscription = Subscription::query()->create([
            'organization_id' => 'org_used',
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);

        app(CouponRedeemer::class)->redeem($coupon, $subscription);
    }

    private function signedInWith(array $permissions): self
    {
        $this->withSession(['auth.user' => [
            'sub' => 'demo|operator',
            'name' => 'Test Operator',
            'email' => 'ops@example.test',
            'org' => 'org_hverdag',
            'picture' => null,
            'permissions' => $permissions,
        ]]);

        return $this;
    }
}
