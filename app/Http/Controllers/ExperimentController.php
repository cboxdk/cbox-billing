<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Exceptions\ExperimentActionDenied;
use App\Billing\Experiments\ExperimentAuthoring;
use App\Billing\Experiments\ExperimentLifecycle;
use App\Billing\Experiments\ExperimentReport;
use App\Billing\Experiments\ExperimentResults;
use App\Billing\Experiments\ValueObjects\ExperimentDraft;
use App\Billing\Experiments\ValueObjects\VariantDraft;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The A/B pricing-experiments console — thin HTTP over {@see ExperimentReport} (reads),
 * {@see ExperimentAuthoring} (create/edit/delete), {@see ExperimentLifecycle} (start/conclude)
 * and {@see ExperimentResults} (the results dashboard). Reads carry `analytics:read`; writes
 * (which mutate the storefront's served pricing) carry `catalog:manage`. Every mutation is
 * audit-logged by the central recording seam via its route name.
 */
class ExperimentController extends Controller
{
    private const string AREA = 'experiments';

    public function index(Request $request, ExperimentReport $report): View
    {
        $search = $this->search($request);

        return view('billing.experiments.index', [
            'activeArea' => self::AREA,
            'activeNav' => 'all',
            'search' => $search,
            'experiments' => $report->paginate($search),
        ]);
    }

    public function create(ExperimentReport $report): View
    {
        return $this->form(null, $report);
    }

    public function edit(Experiment $experiment, ExperimentReport $report): View
    {
        return $this->form($experiment->load('variants'), $report);
    }

    public function store(Request $request, ExperimentAuthoring $authoring): RedirectResponse
    {
        try {
            $experiment = $authoring->create($this->draft($request));
        } catch (ExperimentActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.experiments.show', $experiment->id)
            ->with('status', sprintf('Experiment “%s” created.', $experiment->name));
    }

    public function update(Request $request, Experiment $experiment, ExperimentAuthoring $authoring): RedirectResponse
    {
        try {
            $authoring->update($experiment, $this->draft($request));
        } catch (ExperimentActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.experiments.show', $experiment->id)
            ->with('status', sprintf('Experiment “%s” updated.', $experiment->name));
    }

    public function show(Experiment $experiment, ExperimentResults $results, Config $config, UrlGenerator $url): View
    {
        $experiment->load(['variants.servedTable', 'pricingTable', 'promotedVariant']);

        return view('billing.experiments.show', [
            'activeArea' => self::AREA,
            'activeNav' => 'all',
            'experiment' => $experiment,
            'results' => $results->for($experiment),
            'publicUrl' => $this->publicUrl($config, $url, $experiment->pricingTable?->key),
        ]);
    }

    public function start(Experiment $experiment, ExperimentLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->start($experiment);
        } catch (ExperimentActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf('Experiment “%s” is now running.', $experiment->name));
    }

    public function conclude(Request $request, Experiment $experiment, ExperimentLifecycle $lifecycle): RedirectResponse
    {
        $winner = $this->winner($request, $experiment);

        try {
            $lifecycle->conclude($experiment, $winner);
        } catch (ExperimentActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $winner instanceof ExperimentVariant
            ? sprintf('Experiment concluded — “%s” promoted; the pricing page now serves it.', $winner->label)
            : 'Experiment concluded; the pricing page reverts to its base table.';

        return back()->with('status', $message);
    }

    public function destroy(Experiment $experiment, ExperimentAuthoring $authoring): RedirectResponse
    {
        $name = $experiment->name;
        $authoring->delete($experiment);

        return redirect()
            ->route('billing.experiments')
            ->with('status', sprintf('Experiment “%s” deleted.', $name));
    }

    private function form(?Experiment $experiment, ExperimentReport $report): View
    {
        return view('billing.experiments.form', [
            'activeArea' => self::AREA,
            'activeNav' => 'all',
            'experiment' => $experiment,
            'metrics' => ExperimentMetric::cases(),
            'options' => $report->formOptions(),
        ]);
    }

    private function draft(Request $request): ExperimentDraft
    {
        $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'hypothesis' => ['nullable', 'string', 'max:2000'],
            'primary_metric' => ['required', 'string', 'in:'.implode(',', array_map(static fn (ExperimentMetric $m): string => $m->value, ExperimentMetric::cases()))],
            'pricing_table_id' => ['required', 'integer', 'exists:pricing_tables,id'],
            'variants' => ['required', 'array', 'min:2'],
            'variants.*.label' => ['required', 'string', 'max:80'],
            'variants.*.weight' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.served_pricing_table_id' => ['nullable', 'integer', 'exists:pricing_tables,id'],
            'control' => ['required', 'string'],
        ]);

        return new ExperimentDraft(
            key: $request->string('key')->toString(),
            name: $request->string('name')->toString(),
            hypothesis: $request->filled('hypothesis') ? $request->string('hypothesis')->toString() : null,
            primaryMetric: ExperimentMetric::from($request->string('primary_metric')->toString()),
            pricingTableId: (int) $request->integer('pricing_table_id'),
            variants: $this->variants($request),
        );
    }

    /**
     * @return list<VariantDraft>
     */
    private function variants(Request $request): array
    {
        $rows = $request->input('variants');
        $control = $request->input('control');
        $controlKey = is_scalar($control) ? (string) $control : '';

        if (! is_array($rows)) {
            return [];
        }

        $variants = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = $this->stringOrNull($row['label'] ?? null);

            // A row with no label is an empty template row — skip it.
            if ($label === null) {
                continue;
            }

            $variants[] = new VariantDraft(
                label: $label,
                isControl: (string) $index === $controlKey,
                weight: $this->weight($row['weight'] ?? null),
                servedPricingTableId: $this->intOrNull($row['served_pricing_table_id'] ?? null),
            );
        }

        return $variants;
    }

    private function winner(Request $request, Experiment $experiment): ?ExperimentVariant
    {
        $winnerId = $this->intOrNull($request->input('winner'));

        if ($winnerId === null) {
            return null;
        }

        return $experiment->variants()->whereKey($winnerId)->first();
    }

    private function weight(mixed $value): int
    {
        $int = $this->intOrNull($value);

        // A blank weight defaults to an even 1 (equal split); a control still needs traffic.
        return $int === null ? 1 : max(0, $int);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function publicUrl(Config $config, UrlGenerator $url, ?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $base = $config->get('billing.storefront.embed_base_url');
        $path = route('storefront.show', ['key' => $key], false);

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').$path;
        }

        return $url->to($path);
    }

    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }
}
