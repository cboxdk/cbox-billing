<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\ValueObjects\AddOnRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-review remediation (section 4): the CONSOLE add-on confirm must thread the previewed "due now"
 * gross so the depth service's stale-preview guard fires, exactly as the API path already does — a
 * confirm whose previewed gross no longer matches the freshly-computed proration is REJECTED
 * (back-with-error), never a silent mischarge. A confirm carrying the matching gross applies.
 */
class ConsoleAddOnDriftGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    private function subscribe(string $org): int
    {
        Organization::query()->create(['id' => $org, 'name' => ucfirst($org), 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
        $plan = Plan::query()->where('key', 'team')->firstOrFail();

        return (int) app(SubscribesOrganizations::class)->subscribe(Organization::query()->findOrFail($org), $plan, 1, 'DKK')->id;
    }

    public function test_a_console_add_on_confirm_with_a_drifted_expected_gross_is_rejected(): void
    {
        $id = $this->subscribe('org_addon');

        // Confirm carries an expected "due now" that does NOT match the fresh proration → rejected.
        $this->withSession($this->session)->post('/subscriptions/'.$id.'/addons', [
            'key' => 'extra_seats', 'price_minor' => 10_000, 'currency' => 'DKK',
            'alignment' => 'aligned', 'credit_allotment' => 0, 'expected_due_minor' => 1,
        ])->assertRedirect()->assertSessionHas('error');

        // Nothing was attached (deny-by-default — no silent mischarge).
        $this->assertSame(0, SubscriptionAddOn::query()->where('subscription_id', $id)->count());
    }

    public function test_a_console_add_on_confirm_with_the_matching_gross_applies(): void
    {
        $id = $this->subscribe('org_addon_ok');

        // Preview to learn the exact "due now" gross the confirm must carry.
        $preview = $this->withSession($this->session)->post('/subscriptions/'.$id.'/addons/preview', [
            'key' => 'extra_seats', 'price_minor' => 10_000, 'currency' => 'DKK',
            'alignment' => 'aligned', 'credit_allotment' => 0,
        ])->assertOk();

        // The review page renders the previewed gross into the confirm's hidden expected_due_minor.
        $expected = $this->currentGross($id);

        $this->withSession($this->session)->post('/subscriptions/'.$id.'/addons', [
            'key' => 'extra_seats', 'price_minor' => 10_000, 'currency' => 'DKK',
            'alignment' => 'aligned', 'credit_allotment' => 0, 'expected_due_minor' => $expected,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, SubscriptionAddOn::query()->where('subscription_id', $id)->where('key', 'extra_seats')->count());
        $preview->assertSee('Charge now');
    }

    private function currentGross(int $subscriptionId): int
    {
        $subscription = Subscription::query()->with(['plan', 'organization'])->findOrFail($subscriptionId);
        $request = new AddOnRequest(
            key: 'extra_seats', priceMinor: 10_000, currency: 'DKK',
            alignment: AddOnAlignment::Aligned,
        );

        return app(ManagesSubscriptionDepth::class)
            ->previewAddOn($subscription, $request)->grossDueNow->minor();
    }
}
