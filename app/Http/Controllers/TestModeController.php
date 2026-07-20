<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\TestMode\Enums\TestChargeOutcome;
use App\Billing\TestMode\TestClockAdvancer;
use App\Billing\TestMode\TestClockReport;
use App\Models\Subscription;
use App\Models\TestClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The console's sandbox surface: the persistent test-mode toggle and the test-clock manager.
 * Thin over the {@see CurrentUser} session seam (toggle), the {@see TestClockReport} (reads),
 * and the {@see TestClockAdvancer} (the fast-forward). Test clocks and their bound
 * subscriptions are always test-plane objects, so the clock actions force the ambient
 * {@see BillingContext} to test regardless of the console's live/test toggle.
 */
class TestModeController extends Controller
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly BillingContext $context,
        private readonly EnvironmentRegistry $environments,
    ) {}

    /** Flip the console between production and the default sandbox (BC toggle; posts `enabled`). */
    public function toggle(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $this->current->setTestMode($enabled);

        return redirect()->back()->with(
            'status',
            $enabled ? 'Sandbox ON — you are viewing the sandbox dataset.' : 'Back on production data.',
        );
    }

    /**
     * Switch the console to a named environment (the persistent environment switcher). The key
     * must name a real, seeded environment — an unknown key is refused (deny-by-default) so the
     * console can never land on a plane that does not exist.
     */
    public function switchEnvironment(Request $request): RedirectResponse
    {
        $request->validate(['environment' => ['required', 'string', 'max:255']]);

        $key = $request->string('environment')->toString();
        $environment = $this->environments->find($key);

        if ($environment === null || ! $environment->exists) {
            return redirect()->back()->with('status', 'Unknown environment — no change.');
        }

        $this->current->setActiveEnvironment($environment->key);

        return redirect()->back()->with('status', sprintf(
            'Switched to “%s”%s.',
            $environment->name,
            $environment->isProduction() ? '' : ' — sandbox data only, no real charges or emails',
        ));
    }

    public function index(TestClockReport $report): View
    {
        $this->context->setMode(BillingMode::Test);

        return view('billing.test-mode.clocks', [
            'activeArea' => 'settings',
            'activeNav' => 'test-clocks',
            'clocks' => $report->clocks(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->context->setMode(BillingMode::Test);

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'now_at' => ['nullable', 'date'],
        ]);

        $clock = TestClock::query()->create([
            'name' => $request->string('name')->toString(),
            'now_at' => $request->filled('now_at') ? Carbon::parse($request->string('now_at')->toString()) : Carbon::now(),
            'created_by_sub' => $this->current->user()?->sub,
        ]);

        return redirect()
            ->route('billing.test-mode.clocks.show', $clock)
            ->with('status', sprintf('Test clock “%s” created.', $clock->name));
    }

    public function show(TestClock $testClock, TestClockReport $report): View
    {
        $this->context->setMode(BillingMode::Test);

        return view('billing.test-mode.clock', [
            'activeArea' => 'settings',
            'activeNav' => 'test-clocks',
            'detail' => $report->detail($testClock),
            'bindable' => $report->bindableSubscriptions(),
        ]);
    }

    public function advance(Request $request, TestClock $testClock, TestClockAdvancer $advancer): RedirectResponse
    {
        $this->context->setMode(BillingMode::Test);

        $request->validate([
            'target' => ['required', 'date'],
        ]);

        $result = $advancer->advance($testClock, CarbonImmutable::parse($request->string('target')->toString()));

        return redirect()
            ->route('billing.test-mode.clocks.show', $testClock)
            ->with('status', sprintf(
                'Advanced to %s — %d renewal(s), %d trial conversion(s), %d dunning attempt(s), %d invoice(s).',
                $result->to->format('Y-m-d H:i'),
                $result->renewals,
                $result->trialConversions,
                $result->dunningAttempts,
                $result->invoices,
            ));
    }

    public function outcome(Request $request, TestClock $testClock): RedirectResponse
    {
        $this->context->setMode(BillingMode::Test);

        $request->validate([
            'charge_outcome' => ['required', 'string', 'in:succeed,decline'],
        ]);

        $testClock->forceFill([
            'charge_outcome' => TestChargeOutcome::parse($request->string('charge_outcome')->toString())->value,
        ])->save();

        return redirect()
            ->route('billing.test-mode.clocks.show', $testClock)
            ->with('status', 'Charge outcome updated.');
    }

    public function bind(Request $request, TestClock $testClock): RedirectResponse
    {
        $this->context->setMode(BillingMode::Test);

        $request->validate([
            'subscription_id' => ['required', 'integer', 'exists:subscriptions,id'],
        ]);

        $subscription = Subscription::query()->findOrFail($request->integer('subscription_id'));
        $subscription->forceFill(['test_clock_id' => $testClock->id])->save();

        return redirect()
            ->route('billing.test-mode.clocks.show', $testClock)
            ->with('status', 'Subscription bound to this clock.');
    }

    public function unbind(TestClock $testClock, string $subscription): RedirectResponse
    {
        // `$subscription` is the raw route id (not model-bound): route binding runs before the
        // plane is forced to test, so we resolve it here, in test mode, instead.
        $this->context->setMode(BillingMode::Test);

        $row = Subscription::query()->where('test_clock_id', $testClock->id)->findOrFail($subscription);
        $row->forceFill(['test_clock_id' => null])->save();

        return redirect()
            ->route('billing.test-mode.clocks.show', $testClock)
            ->with('status', 'Subscription unbound from this clock.');
    }
}
