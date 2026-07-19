<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Payments\Contracts\SchedulesRetries;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Payments\Dunning\DunningStrategyRepository;
use App\Billing\Payments\Dunning\RetryPlan;
use App\Models\DunningStrategy;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The adaptive-dunning strategy console (Settings → Retry strategy). Operators view the
 * effective per-decline-category recovery plan (config defaults ⊕ any DB override) and tune a
 * category's curve/heuristics at runtime — persisted to `dunning_strategies` and read straight
 * back by {@see SchedulesRetries}, no redeploy. Reads carry `settings:read`; writes carry
 * `settings:manage`.
 *
 * Thin: it projects the resolved {@see RetryPlan}s for the index,
 * validates the edit form, and upserts/clears the override row. The scheduling logic lives in
 * the strategy, not here.
 */
class DunningStrategyController extends Controller
{
    public function index(SchedulesRetries $strategy, DunningStrategyRepository $overrides, Config $config): View
    {
        $rows = [];

        foreach (DeclineCategory::all() as $category) {
            $plan = $strategy->planFor($category);
            $rows[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'pill' => $category->pill(),
                'description' => $category->description(),
                'retry' => $plan->retry,
                'backoff' => $plan->backoffDays,
                'max_attempts' => $plan->maxAttempts,
                'avoid_weekends' => $plan->avoidWeekends,
                'align_to_payday' => $plan->alignToPayday,
                'overridden' => $overrides->forCategory($category) !== null,
                'editable' => $category !== DeclineCategory::Hard,
            ];
        }

        $window = $config->get('billing.dunning.strategies.max_window_days', 30);
        $paydayDays = $config->get('billing.dunning.strategies.payday_days', [1, 15]);
        $quietWeekdays = $config->get('billing.dunning.strategies.quiet_weekdays', [6, 7]);

        return view('billing.settings.dunning', [
            'activeArea' => 'settings',
            'activeNav' => 'settings',
            'strategies' => $rows,
            'window' => is_numeric($window) ? (int) $window : 30,
            'paydayDays' => is_array($paydayDays) ? $paydayDays : [1, 15],
            'quietWeekdays' => is_array($quietWeekdays) ? $quietWeekdays : [6, 7],
        ]);
    }

    public function edit(string $category, SchedulesRetries $strategy, DunningStrategyRepository $overrides): View
    {
        $declineCategory = $this->resolve($category);
        $plan = $strategy->planFor($declineCategory);

        return view('billing.settings.dunning-form', [
            'activeArea' => 'settings',
            'activeNav' => 'settings',
            'category' => $declineCategory->value,
            'label' => $declineCategory->label(),
            'description' => $declineCategory->description(),
            'plan' => $plan,
            'overridden' => $overrides->forCategory($declineCategory) !== null,
        ]);
    }

    public function update(Request $request, string $category, DunningStrategyRepository $overrides): RedirectResponse
    {
        $declineCategory = $this->resolve($category);

        $request->validate([
            'backoff_days' => ['required', 'string', 'max:120'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'retry' => ['nullable', 'boolean'],
            'avoid_weekends' => ['nullable', 'boolean'],
            'align_to_payday' => ['nullable', 'boolean'],
        ]);

        $days = $this->parseDays($request->string('backoff_days')->toString());

        if ($days === []) {
            return back()->withInput()->with('error', 'Enter at least one positive day-offset, e.g. "2, 5, 9, 14".');
        }

        DunningStrategy::query()->updateOrCreate(
            ['category' => $declineCategory->value],
            [
                // A hard category is never retried regardless of what is submitted.
                'retry' => $declineCategory !== DeclineCategory::Hard && $request->boolean('retry'),
                'backoff_days' => $days,
                'max_attempts' => $request->filled('max_attempts') ? $request->integer('max_attempts') : null,
                'avoid_weekends' => $request->boolean('avoid_weekends'),
                'align_to_payday' => $request->boolean('align_to_payday'),
            ],
        );

        $overrides->flush();

        return redirect()
            ->route('billing.settings.dunning')
            ->with('status', 'Recovery strategy for “'.$declineCategory->label().'” saved.');
    }

    public function reset(string $category, DunningStrategyRepository $overrides): RedirectResponse
    {
        $declineCategory = $this->resolve($category);

        DunningStrategy::query()->where('category', $declineCategory->value)->delete();
        $overrides->flush();

        return redirect()
            ->route('billing.settings.dunning')
            ->with('status', '“'.$declineCategory->label().'” reverted to the shipped defaults.');
    }

    private function resolve(string $category): DeclineCategory
    {
        return DeclineCategory::tryFrom($category) ?? abort(404);
    }

    /**
     * Parse a "2, 5, 9, 14" day list into a clean, positive list<int>.
     *
     * @return list<int>
     */
    private function parseDays(string $input): array
    {
        $out = [];

        foreach (explode(',', $input) as $piece) {
            $piece = trim($piece);

            if ($piece !== '' && ctype_digit($piece) && (int) $piece > 0) {
                $out[] = (int) $piece;
            }
        }

        return $out;
    }
}
