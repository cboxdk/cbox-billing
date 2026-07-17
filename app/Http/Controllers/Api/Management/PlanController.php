<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Catalog\PlanCatalogView;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/plans` — the sellable catalog, each plan priced in the caller's account
 * currency (or an explicit `?currency=` for signup, before an account exists). A plan
 * not priced in the resolved currency is omitted rather than shown at a fabricated rate
 * (deny-by-default). Thin: resolve the currency, delegate the projection to
 * {@see PlanCatalogView}.
 */
class PlanController extends ApiController
{
    public function index(Request $request, PlanCatalogView $view, ResolvesAccountCurrency $currencies, Config $config): JsonResponse
    {
        $currency = $this->currency($request, $currencies, $config);

        $productId = $this->identity($request)->productId;

        $plans = Plan::query()
            ->with(['prices', 'entitlements.meter'])
            ->where('active', true)
            // A product-bound token sees only its own product's catalog (shared instance).
            ->when($productId !== null, static fn ($query) => $query->where('product_id', $productId))
            ->orderBy('id')
            ->get()
            ->filter(static fn (Plan $plan): bool => $plan->prices->contains('currency', $currency));

        return new JsonResponse([
            'currency' => $currency,
            'data' => $view->present($plans, $currency),
        ]);
    }

    /** The currency to price the catalog in: an explicit `?currency=`, else the caller's account, else the app default. */
    private function currency(Request $request, ResolvesAccountCurrency $currencies, Config $config): string
    {
        $requested = $request->query('currency');

        if (is_string($requested) && $requested !== '') {
            return strtoupper($requested);
        }

        $identity = $this->identity($request);
        $organization = $identity->organizationId !== null
            ? Organization::query()->find($identity->organizationId)
            : null;

        if ($organization instanceof Organization) {
            return $currencies->for($organization);
        }

        $default = $config->get('billing.default_currency', 'DKK');

        return is_string($default) ? $default : 'DKK';
    }
}
