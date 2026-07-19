<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Fx\FxRateRefresher;
use App\Billing\Fx\FxRateRepository;
use App\Models\FxRate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The FX-rates admin (Settings → FX rates). Shows the current effective rates and their
 * provenance (ECB vs operator override) with the exact as-of date, runs a refresh pull now, and
 * lets an operator author an override rate for a pair ECB does not cover or a treasury-fixed
 * rate. Reads carry `settings:read`, writes `settings:manage` (declared on the routes). Thin over
 * {@see FxRateRepository} and {@see FxRateRefresher} — no arithmetic here.
 */
class FxRateController extends Controller
{
    public function index(Request $request, FxRateRepository $rates): View
    {
        $asOf = CarbonImmutable::now();
        $sources = config('billing.fx.sources', []);
        $ecbUrl = config('billing.fx.ecb.url', '');

        return view('billing.fx-rates', [
            'activeArea' => 'settings',
            'activeNav' => 'fx',
            'rates' => $rates->latestRates($asOf),
            'asOf' => $asOf,
            'sources' => is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [],
            'ecbUrl' => is_string($ecbUrl) ? $ecbUrl : '',
        ]);
    }

    public function refresh(FxRateRefresher $refresher): RedirectResponse
    {
        $results = $refresher->refresh();
        $persisted = array_sum(array_map(static fn ($r): int => $r->count, $results));
        $failed = array_filter($results, static fn ($r): bool => ! $r->ok);

        $message = sprintf('Refreshed FX rates — %d rate(s) persisted.', $persisted);

        if ($failed !== []) {
            $message .= ' Failed: '.implode(', ', array_map(static fn ($r): string => $r->origin->label(), $failed)).'.';
        }

        return redirect()->route('billing.settings.fx')->with('status', $message);
    }

    public function storeOverride(Request $request): RedirectResponse
    {
        $request->validate([
            'base' => ['required', 'string', 'size:3', 'alpha'],
            'quote' => ['required', 'string', 'size:3', 'alpha', 'different:base'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'as_of_date' => ['required', 'date'],
        ]);

        $base = strtoupper($request->string('base')->toString());
        $quote = strtoupper($request->string('quote')->toString());

        FxRate::query()->updateOrCreate(
            [
                'as_of_date' => CarbonImmutable::parse($request->date('as_of_date'))->toDateString(),
                'base' => $base,
                'quote' => $quote,
                'source' => 'override',
            ],
            ['rate' => $request->string('rate')->toString()],
        );

        return redirect()->route('billing.settings.fx')
            ->with('status', sprintf('Override %s → %s saved.', $base, $quote));
    }
}
