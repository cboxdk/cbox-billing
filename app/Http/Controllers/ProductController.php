<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\ProductAuthoring;
use App\Billing\Reporting\ProductReport;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Products console — thin HTTP over {@see ProductReport} (reads) and
 * {@see ProductAuthoring} (writes). Delete is guarded server-side: a product that still
 * groups plans is archived, never hard-deleted, so catalog history is never orphaned.
 */
class ProductController extends Controller
{
    public function index(Request $request, ProductReport $report): View
    {
        $search = $this->search($request);

        return view('billing.products', [
            'activeArea' => 'catalog',
            'activeNav' => 'products',
            'search' => $search,
            'products' => $report->paginate($search),
        ]);
    }

    public function show(Product $product, ProductReport $report): View
    {
        return view('billing.product-detail', [
            'activeArea' => 'catalog',
            'activeNav' => 'products',
            'product' => $report->find($product->id),
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(Product $product): View
    {
        return $this->form($product);
    }

    public function store(Request $request, ProductAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $product = $authoring->create($data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.products.show', $product->id)
            ->with('status', sprintf('Product “%s” created.', $product->name));
    }

    public function update(Request $request, Product $product, ProductAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request, $product);

        try {
            $authoring->update($product, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.products.show', $product->id)
            ->with('status', sprintf('Product “%s” updated.', $product->name));
    }

    public function archive(Product $product, ProductAuthoring $authoring): RedirectResponse
    {
        $authoring->archive($product);

        return back()->with('status', sprintf('Product “%s” archived.', $product->name));
    }

    public function unarchive(Product $product, ProductAuthoring $authoring): RedirectResponse
    {
        $authoring->unarchive($product);

        return back()->with('status', sprintf('Product “%s” reinstated.', $product->name));
    }

    public function destroy(Product $product, ProductAuthoring $authoring): RedirectResponse
    {
        $name = $product->name;

        try {
            $authoring->delete($product);
        } catch (CatalogActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.products')
            ->with('status', sprintf('Product “%s” deleted.', $name));
    }

    private function form(?Product $product): View
    {
        return view('billing.product-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'products',
            'product' => $product,
        ]);
    }

    /**
     * @return array{key: string, name: string, description: ?string}
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        return [
            'key' => $request->string('key')->toString(),
            'name' => $request->string('name')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
        ];
    }

    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }
}
