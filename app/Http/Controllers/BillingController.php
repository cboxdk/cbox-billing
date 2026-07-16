<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Reporting\CatalogReport;
use App\Billing\Reporting\CustomerReport;
use App\Billing\Reporting\InvoiceReport;
use App\Billing\Reporting\PricingReport;
use App\Billing\Reporting\RevenueMetrics;
use App\Billing\Reporting\SettingsReport;
use App\Billing\Reporting\SubscriptionReport;
use App\Billing\Reporting\UsageReport;
use App\Billing\Support\SubscriptionStanding;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP layer over the billing read models — each action resolves a read model,
 * hands its real data to the app-shell view, and does nothing else. No business logic
 * lives here; the read models compose it from the models and the engine.
 */
class BillingController extends Controller
{
    public function dashboard(RevenueMetrics $metrics, InvoiceReport $invoices, SettingsReport $settings): View
    {
        return view('billing.dashboard', [
            'activeArea' => 'home',
            'activeNav' => 'dashboard',
            'metrics' => $metrics,
            'revenue' => $metrics->revenue(),
            'primaryCurrency' => $metrics->primaryCurrency(),
            'counts' => SubscriptionStanding::counts(),
            'planBreakdown' => $metrics->planBreakdown(),
            'recentInvoices' => $invoices->list(limit: 7),
            'gateways' => $settings->gateways(),
        ]);
    }

    public function subscriptions(Request $request, SubscriptionReport $report): View
    {
        $status = $this->filter($request, ['active', 'trialing', 'past_due', 'canceled']);

        return view('billing.subscriptions', [
            'activeArea' => 'subscriptions',
            'activeNav' => $status ?? 'all',
            'status' => $status,
            'counts' => $report->counts(),
            'subscriptions' => $report->list($status),
        ]);
    }

    public function subscription(Subscription $subscription, SubscriptionReport $report): View
    {
        return view('billing.subscription-detail', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'all',
            'subscription' => $report->find($subscription->id),
        ]);
    }

    public function invoices(Request $request, InvoiceReport $report): View
    {
        $status = $this->filter($request, ['open', 'paid', 'draft']);

        return view('billing.invoices', [
            'activeArea' => 'invoices',
            'activeNav' => $status ?? 'all',
            'status' => $status,
            'counts' => $report->counts(),
            'invoices' => $report->list($status),
        ]);
    }

    public function invoice(Invoice $invoice): View
    {
        $invoice->load(['organization', 'lines']);

        return view('billing.invoice-detail', [
            'activeArea' => 'invoices',
            'activeNav' => 'all',
            'invoice' => $invoice,
        ]);
    }

    public function invoicePdf(Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        return new Response($renderer->render($invoice), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($invoice).'"',
        ]);
    }

    public function usage(Request $request, UsageReport $report): View
    {
        $selected = $request->query('org');
        $organization = is_string($selected) ? Organization::query()->find($selected) : null;

        return view('billing.usage', [
            'activeArea' => 'usage',
            'activeNav' => 'meters',
            'selectedOrg' => $organization?->id,
            'organizations' => $report->forAllOrganizations(),
        ]);
    }

    public function catalog(CatalogReport $report): View
    {
        return view('billing.catalog', [
            'activeArea' => 'catalog',
            'activeNav' => 'products',
            'products' => $report->products(),
        ]);
    }

    public function pricing(Request $request, PricingReport $report): View
    {
        $comparison = $report->comparison();
        $currency = $this->currency($request, $comparison['currencies']);

        return view('billing.pricing', [
            'activeArea' => 'catalog',
            'activeNav' => 'plans',
            'currency' => $currency,
            'currencies' => $comparison['currencies'],
            'meters' => $comparison['meters'],
            'plans' => $comparison['plans'],
        ]);
    }

    public function customers(CustomerReport $report): View
    {
        return view('billing.customers', [
            'activeArea' => 'customers',
            'activeNav' => 'organizations',
            'customers' => $report->list(),
        ]);
    }

    public function customer(Organization $organization, CustomerReport $report): View
    {
        return view('billing.customer-detail', [
            'activeArea' => 'customers',
            'activeNav' => 'organizations',
            'customer' => $report->find($organization->id),
        ]);
    }

    public function settings(SettingsReport $report): View
    {
        return view('billing.settings', [
            'activeArea' => 'settings',
            'activeNav' => 'sellers',
            'sellers' => $report->sellers(),
            'taxRegistrations' => $report->taxRegistrations(),
            'gateways' => $report->gateways(),
            'apiTokens' => $report->apiTokens(),
            'webhook' => $report->webhook(),
        ]);
    }

    /**
     * The requested status filter when it is one of the allowed values, else null (all).
     *
     * @param  list<string>  $allowed
     */
    private function filter(Request $request, array $allowed): ?string
    {
        $status = $request->query('status');

        return is_string($status) && in_array($status, $allowed, true) ? $status : null;
    }

    /**
     * The requested `?currency=` when it is one the catalog is priced in, else the first
     * available currency (else the app default).
     *
     * @param  list<string>  $available
     */
    private function currency(Request $request, array $available): string
    {
        $requested = $request->query('currency');

        if (is_string($requested) && in_array(strtoupper($requested), $available, true)) {
            return strtoupper($requested);
        }

        $default = config('billing.default_currency', 'DKK');

        return $available[0] ?? (is_string($default) ? $default : 'DKK');
    }
}
