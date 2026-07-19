<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Reporting\AccessGrantReport;
use App\Billing\Reporting\CatalogReport;
use App\Billing\Reporting\CustomerAuditLog;
use App\Billing\Reporting\CustomerPaymentMethods;
use App\Billing\Reporting\CustomerReport;
use App\Billing\Reporting\InvoiceReport;
use App\Billing\Reporting\PricingReport;
use App\Billing\Reporting\RecoveryAnalytics;
use App\Billing\Reporting\RevenueMetrics;
use App\Billing\Reporting\SettingsReport;
use App\Billing\Reporting\SubscriptionReport;
use App\Billing\Reporting\UsageReport;
use App\Billing\Reporting\WalletReport;
use App\Billing\Retirement\PlanRetirementService;
use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Support\SubscriptionStanding;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP layer over the billing read models — each action resolves a read model,
 * hands its real data to the app-shell view, and does nothing else. No business logic
 * lives here; the read models compose it from the models and the engine.
 */
class BillingController extends Controller
{
    public function dashboard(RevenueMetrics $metrics, InvoiceReport $invoices, SettingsReport $settings, RecoveryAnalytics $recovery): View
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
            // Adaptive-dunning recovery at a glance (the dashboard card).
            'recovery' => $recovery->summary(),
        ]);
    }

    public function subscriptions(Request $request, SubscriptionReport $report, PlanRetirementService $retirements): View
    {
        $status = $this->filter($request, ['active', 'trialing', 'past_due', 'paused', 'non_renewing', 'canceled']);
        $search = $this->search($request);

        return view('billing.subscriptions', [
            'activeArea' => 'subscriptions',
            'activeNav' => $status ?? 'all',
            'status' => $status,
            'search' => $search,
            'counts' => $report->counts(),
            'subscriptions' => $report->paginate($status, $search),
            'unresolvedRetirements' => $this->unresolvedRetirements($retirements),
        ]);
    }

    public function dunning(Request $request, SubscriptionReport $report, RecoveryAnalytics $analytics): View
    {
        $search = $this->search($request);

        return view('billing.dunning', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'dunning',
            'search' => $search,
            'counts' => $report->counts(),
            'retries' => $report->paginateDunning($search),
            // Recovery analytics: the payoff of adaptive dunning, computed over real retry rows.
            'recovery' => $analytics->summary(),
            'byCategory' => $analytics->byCategory(),
        ]);
    }

    public function subscription(
        Subscription $subscription,
        SubscriptionReport $report,
        CancellationSurvey $survey,
        RetentionOffers $offers,
        PlanRetirementService $retirements,
        ManagesSeats $seats,
    ): View {
        $subscription->loadMissing(['plan.defaultSuccessor', 'organization', 'pendingPlan']);

        return view('billing.subscription-detail', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'all',
            'subscription' => $report->find($subscription->id),
            // The cancel UI renders whatever the bound retention seam returns — the app's
            // basic survey/offers by default, the plugin's rich flow when composed in.
            'retentionReasons' => $this->retentionReasons($survey, $subscription),
            'retentionOffers' => $this->retentionOffers($offers, $subscription),
            'sunset' => $retirements->noticeFor($subscription),
            // The seat picture: purchased Full seats (billed), assigned members (Full) and
            // eligible-but-unassigned members (Light, free). Null when the subscription is
            // not serving (no seat authority to act against).
            'seats' => $subscription->isServing() ? $seats->breakdown($subscription) : null,
            'seatTypes' => config('billing.seats.types', []),
        ]);
    }

    public function invoices(Request $request, InvoiceReport $report): View
    {
        $status = $this->filter($request, ['open', 'paid', 'draft']);
        $search = $this->search($request);

        return view('billing.invoices', [
            'activeArea' => 'invoices',
            'activeNav' => $status ?? 'all',
            'status' => $status,
            'search' => $search,
            'counts' => $report->counts(),
            'invoices' => $report->paginate($status, $search),
        ]);
    }

    public function invoice(Invoice $invoice): View
    {
        $invoice->load(['organization', 'lines']);

        return view('billing.invoice-detail', [
            'activeArea' => 'invoices',
            'activeNav' => 'all',
            'invoice' => $invoice,
            // The credit notes issued against this invoice (refunds/adjustments), cross-linked.
            'creditNotes' => CreditNote::query()
                ->where('invoice_number', $invoice->number)
                ->orderByDesc('issued_at')
                ->get(),
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
        $search = $this->search($request);

        $perPage = 8;

        // The cards to render: a single selected org (chip) computed on its own, else the
        // optionally-searched fleet paginated AT THE DATABASE so only the visible page's usage
        // is ever computed (PERF-1).
        if ($organization !== null) {
            $page = LengthAwarePaginator::resolveCurrentPage();
            $cards = new LengthAwarePaginator(
                [$report->forOrganization($organization)],
                1,
                $perPage,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => $request->query()],
            );
        } else {
            $cards = $report->paginate($search, $perPage);
        }

        return view('billing.usage', [
            'activeArea' => 'usage',
            'activeNav' => 'meters',
            'selectedOrg' => $organization?->id,
            'search' => $search,
            'organizations' => $report->organizationChips(),
            'cards' => $cards,
        ]);
    }

    public function catalog(CatalogReport $report): View
    {
        return view('billing.catalog', [
            'activeArea' => 'catalog',
            'activeNav' => 'catalog',
            'products' => $report->products(),
            'successorChoices' => $report->successorChoices(),
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

    public function customers(Request $request, CustomerReport $report): View
    {
        $search = $this->search($request);

        return view('billing.customers', [
            'activeArea' => 'customers',
            'activeNav' => 'organizations',
            'search' => $search,
            'customers' => $report->paginate($search),
        ]);
    }

    public function customer(
        Organization $organization,
        CustomerReport $report,
        WalletReport $wallet,
        CustomerAuditLog $audit,
        CustomerPaymentMethods $paymentMethods,
        AccessGrantReport $accessGrants,
    ): View {
        return view('billing.customer-detail', [
            'activeArea' => 'customers',
            'activeNav' => 'organizations',
            'customer' => $report->find($organization->id),
            'organization' => $organization,
            'wallet' => $wallet->forOrganization($organization->id),
            'events' => $audit->forOrganization($organization->id),
            'payment' => $paymentMethods->forOrganization($organization),
            'accessGrants' => $accessGrants->forOrganization($organization->id),
            'redemptions' => $report->redemptions($organization->id),
        ]);
    }

    public function settings(Request $request, SettingsReport $report): View
    {
        $tab = $request->query('tab');
        $tabs = ['sellers', 'tax', 'gateways', 'tokens', 'webhooks'];

        return view('billing.settings', [
            'activeArea' => 'settings',
            'activeNav' => is_string($tab) && in_array($tab, $tabs, true) ? $tab : 'sellers',
            'tab' => is_string($tab) && in_array($tab, $tabs, true) ? $tab : 'sellers',
            'sellers' => $report->sellers(),
            'taxRegistrations' => $report->taxRegistrations(),
            'gateways' => $report->gateways(),
            'apiTokens' => $report->apiTokens(),
            'webhook' => $report->webhook(),
            'webhookReceivers' => $report->webhookReceivers(),
            'minted' => null,
        ]);
    }

    /**
     * The churn reasons the bound {@see CancellationSurvey} offers for this subscription.
     *
     * @return list<array{key: string, label: string, requires_comment: bool}>
     */
    private function retentionReasons(CancellationSurvey $survey, Subscription $subscription): array
    {
        return array_map(
            static fn ($reason): array => [
                'key' => $reason->key,
                'label' => $reason->label,
                'requires_comment' => $reason->requiresComment,
            ],
            $survey->reasonsFor($subscription->organization_id, (string) $subscription->id),
        );
    }

    /**
     * The save-offers the bound {@see RetentionOffers} presents for this subscription.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    private function retentionOffers(RetentionOffers $offers, Subscription $subscription): array
    {
        return array_map(
            static fn ($offer): array => [
                'key' => $offer->key,
                'label' => $offer->label,
                'type' => $offer->type->value,
            ],
            $offers->offersFor($subscription->organization_id, (string) $subscription->id),
        );
    }

    /**
     * The subscriptions ops must still resolve — flagged unresolved by the retirement
     * migration (a retired plan with no choice and no default), for the console banner.
     *
     * @return list<array{id: int, org: string}>
     */
    private function unresolvedRetirements(PlanRetirementService $retirements): array
    {
        return array_values($retirements->unresolved()
            ->map(static function ($event): array {
                $organization = $event->subscription?->organization;

                return [
                    'id' => (int) $event->subscription_id,
                    'org' => $organization !== null ? $organization->name : $event->organization_id,
                ];
            })
            ->all());
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

    /** The trimmed `?q=` search term, or null when absent/blank. */
    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
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
