<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Enforcement\Upgrade\ResolvesRequiredFeaturePlan;
use App\Billing\Enforcement\Upgrade\ResolvesRequiredPlan;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Http\Controllers\Api\Management\SubscriptionController;
use App\Http\Controllers\Hosted\PortalController;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Serving unification (platform-review P1 #1): the four hand-rolled
 * `where('status','active')->latest()->first()` subscription lookups — the hosted portal,
 * both management-API and upgrade-gate resolvers, the reconcile seam and the scheduled
 * depth-change pass — must resolve exactly the subscription the enforcement scope
 * ({@see Subscription::scopeServing()}) serves. A trialing and a past-due org keep their
 * grants under `serving()` but were invisible to the narrower `status = active` copies, so
 * before this fix these surfaces returned null or a different row than enforcement.
 */
class ServingSubscriptionUnificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    /** The subscription enforcement serves — the canonical seam every copy must agree with. */
    private function servingSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();
    }

    private static function callPrivate(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($target, $method);

        return $reflection->invoke($target, ...$args);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonActiveServingOrgs(): iterable
    {
        yield 'trialing org' => ['org_aula'];
        yield 'past-due org' => ['org_nordwind'];
    }

    #[DataProvider('nonActiveServingOrgs')]
    public function test_all_lookups_resolve_the_same_subscription_enforcement_serves(string $org): void
    {
        $expected = $this->servingSubscription($org);

        // The org is serving under a non-`active` status — exactly the case the old copies dropped.
        $this->assertInstanceOf(Subscription::class, $expected);
        $this->assertNotSame('active', $expected->status->value);
        $this->assertTrue($expected->isServing());

        // Reconcile seam: Organization::activeSubscription() now routes through serving().
        $viaOrg = Organization::query()->findOrFail($org)->activeSubscription();
        $this->assertInstanceOf(Subscription::class, $viaOrg);
        $this->assertSame($expected->id, $viaOrg->id);

        // Hosted portal.
        $viaPortal = self::callPrivate(app(PortalController::class), 'activeSubscription', $org);
        $this->assertInstanceOf(Subscription::class, $viaPortal);
        $this->assertSame($expected->id, $viaPortal->id);

        // Management API.
        $viaApi = self::callPrivate(app(SubscriptionController::class), 'activeSubscription', $org);
        $this->assertInstanceOf(Subscription::class, $viaApi);
        $this->assertSame($expected->id, $viaApi->id);

        // Both upgrade-gate resolvers resolve the org's current plan from the same serving row.
        $planViaMeterGate = self::callPrivate(app(ResolvesRequiredPlan::class), 'currentPlan', $org);
        $this->assertInstanceOf(Plan::class, $planViaMeterGate);
        $this->assertSame($expected->plan_id, $planViaMeterGate->id);

        $planViaFeatureGate = self::callPrivate(app(ResolvesRequiredFeaturePlan::class), 'currentPlan', $org);
        $this->assertInstanceOf(Plan::class, $planViaFeatureGate);
        $this->assertSame($expected->plan_id, $planViaFeatureGate->id);
    }

    #[DataProvider('nonActiveServingOrgs')]
    public function test_scheduled_depth_change_pass_enacts_changes_on_serving_non_active_subscriptions(string $org): void
    {
        $subscription = $this->servingSubscription($org);
        $this->assertInstanceOf(Subscription::class, $subscription);

        // A plan the org is not already on, to schedule as a due change.
        $target = Plan::query()->where('active', true)->where('id', '!=', $subscription->plan_id)->firstOrFail();

        $subscription->forceFill([
            'pending_plan_id' => $target->id,
            'pending_effective_at' => Carbon::now()->subDay(),
        ])->save();

        // Enforcement serves this subscription, so the depth pass must enact its due change —
        // the old `status = active` filter skipped trialing/past-due rows entirely.
        $applied = app(ManagesSubscriptionDepth::class)->applyDueScheduledChanges();
        $this->assertGreaterThanOrEqual(1, $applied);

        $reread = $subscription->fresh();
        $this->assertInstanceOf(Subscription::class, $reread);
        $this->assertSame($target->id, $reread->plan_id);
        $this->assertNull($reread->pending_plan_id);
    }
}
