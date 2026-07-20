<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Storefront\Exceptions\PricingTableActionDenied;
use App\Billing\Storefront\PricingTableAuthoring;
use App\Billing\Storefront\PricingTablePresenter;
use App\Billing\Storefront\PricingTableReport;
use App\Billing\Storefront\ValueObjects\ColumnDraft;
use App\Billing\Storefront\ValueObjects\PricingTableDraft;
use App\Models\PricingTable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The pricing-tables console — thin HTTP over {@see PricingTableReport} (reads),
 * {@see PricingTableAuthoring} (writes) and {@see PricingTablePresenter} (the live preview). Sits
 * in the catalog area under the same `catalog:read` / `catalog:manage` gate as the rest of the
 * catalog. The detail page renders the ACTUAL table (an iframe onto the public embed route) plus
 * the copy-paste embed snippets, so what an operator authors is exactly what a marketing site ships.
 */
class PricingTableController extends Controller
{
    public function index(Request $request, PricingTableReport $report): View
    {
        $search = $this->search($request);

        return view('billing.pricing-tables.index', [
            'activeArea' => 'catalog',
            'activeNav' => 'pricing-tables',
            'search' => $search,
            'tables' => $report->paginate($search),
        ]);
    }

    public function show(PricingTable $pricingTable, Config $config, UrlGenerator $url): View
    {
        return view('billing.pricing-tables.show', [
            'activeArea' => 'catalog',
            'activeNav' => 'pricing-tables',
            'table' => $pricingTable->load(['columns.plan', 'columns.annualPlan', 'featureRows.feature', 'sellerEntity']),
            'publicUrl' => $this->publicUrl($config, $url, $pricingTable->key, 'storefront.show'),
            'embedUrl' => $this->publicUrl($config, $url, $pricingTable->key, 'storefront.embed'),
            'loaderUrl' => $this->publicUrl($config, $url, $pricingTable->key, 'storefront.loader'),
        ]);
    }

    public function create(PricingTableReport $report): View
    {
        return $this->form(null, $report);
    }

    public function edit(PricingTable $pricingTable, PricingTableReport $report): View
    {
        return $this->form($pricingTable, $report);
    }

    public function store(Request $request, PricingTableAuthoring $authoring): RedirectResponse
    {
        $draft = $this->draft($request);

        try {
            $table = $authoring->create($draft);
        } catch (PricingTableActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.pricing-tables.show', $table->id)
            ->with('status', sprintf('Pricing table “%s” created.', $table->name));
    }

    public function update(Request $request, PricingTable $pricingTable, PricingTableAuthoring $authoring): RedirectResponse
    {
        $draft = $this->draft($request);

        try {
            $authoring->update($pricingTable, $draft);
        } catch (PricingTableActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.pricing-tables.show', $pricingTable->id)
            ->with('status', sprintf('Pricing table “%s” updated.', $pricingTable->name));
    }

    public function activate(PricingTable $pricingTable, PricingTableAuthoring $authoring): RedirectResponse
    {
        $authoring->setActive($pricingTable, true);

        return back()->with('status', sprintf('Pricing table “%s” is now live.', $pricingTable->name));
    }

    public function deactivate(PricingTable $pricingTable, PricingTableAuthoring $authoring): RedirectResponse
    {
        $authoring->setActive($pricingTable, false);

        return back()->with('status', sprintf('Pricing table “%s” taken offline.', $pricingTable->name));
    }

    public function destroy(PricingTable $pricingTable, PricingTableAuthoring $authoring): RedirectResponse
    {
        $name = $pricingTable->name;
        $authoring->delete($pricingTable);

        return redirect()
            ->route('billing.pricing-tables')
            ->with('status', sprintf('Pricing table “%s” deleted.', $name));
    }

    /** Render the actual table (the public embed) for the console live-preview iframe. */
    public function preview(PricingTable $pricingTable, PricingTablePresenter $presenter): Response
    {
        return new Response(
            view('storefront.table', [
                'table' => $presenter->present($pricingTable),
                'mode' => 'embed',
            ])->render(),
            SymfonyResponse::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function form(?PricingTable $pricingTable, PricingTableReport $report): View
    {
        return view('billing.pricing-tables.form', [
            'activeArea' => 'catalog',
            'activeNav' => 'pricing-tables',
            'table' => $pricingTable?->load(['columns', 'featureRows']),
            'options' => $report->formOptions(),
        ]);
    }

    private function draft(Request $request): PricingTableDraft
    {
        $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'seller_entity_id' => ['nullable', 'string', 'exists:seller_entities,id'],
            'currencies' => ['nullable', 'array'],
            'currencies.*' => ['string', 'size:3'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url_template' => ['nullable', 'string', 'max:2048'],
            'columns' => ['nullable', 'array'],
            'columns.*.plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'columns.*.annual_plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'columns.*.badge' => ['nullable', 'string', 'max:40'],
            'columns.*.highlight' => ['nullable', 'string', 'max:120'],
            'features' => ['nullable', 'array'],
            'features.*' => ['integer', 'exists:features,id'],
        ]);

        return new PricingTableDraft(
            key: $request->string('key')->toString(),
            name: $request->string('name')->toString(),
            sellerEntityId: $request->filled('seller_entity_id') ? $request->string('seller_entity_id')->toString() : null,
            currencies: $this->currencies($request),
            defaultCurrency: $request->filled('default_currency') ? strtoupper($request->string('default_currency')->toString()) : null,
            intervalToggle: $request->boolean('interval_toggle'),
            ctaLabel: $request->filled('cta_label') ? $request->string('cta_label')->toString() : null,
            ctaUrlTemplate: $request->filled('cta_url_template') ? $request->string('cta_url_template')->toString() : null,
            active: $request->boolean('active'),
            columns: $this->columns($request),
            featureIds: $this->featureIds($request),
        );
    }

    /**
     * @return list<string>
     */
    private function currencies(Request $request): array
    {
        $currencies = $request->input('currencies');
        $normalized = [];

        if (is_array($currencies)) {
            foreach ($currencies as $currency) {
                if (is_string($currency) && $currency !== '') {
                    $normalized[] = strtoupper($currency);
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<ColumnDraft>
     */
    private function columns(Request $request): array
    {
        $rows = $request->input('columns');

        if (! is_array($rows)) {
            return [];
        }

        $columns = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $planId = $this->intOrNull($row['plan_id'] ?? null);

            // A row with no plan chosen is an empty template row — skip it.
            if ($planId === null) {
                continue;
            }

            $columns[] = new ColumnDraft(
                planId: $planId,
                annualPlanId: $this->intOrNull($row['annual_plan_id'] ?? null),
                featured: filter_var($row['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                badge: $this->stringOrNull($row['badge'] ?? null),
                highlight: $this->stringOrNull($row['highlight'] ?? null),
            );
        }

        return $columns;
    }

    /**
     * @return list<int>
     */
    private function featureIds(Request $request): array
    {
        $features = $request->input('features');
        $ids = [];

        if (is_array($features)) {
            foreach ($features as $feature) {
                $id = $this->intOrNull($feature);

                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
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

    private function publicUrl(Config $config, UrlGenerator $url, string $key, string $routeName): string
    {
        $base = $config->get('billing.storefront.embed_base_url');
        $path = route($routeName, ['key' => $key], false);

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
