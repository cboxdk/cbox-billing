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
use App\Billing\Retirement\PlanRetirementService;
use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Support\SubscriptionStanding;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
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

    public function subscriptions(Request $request, SubscriptionReport $report, PlanRetirementService $retirements): View
    {
        $status = $this->filter($request, ['active', 'trialing', 'past_due', 'paused', 'non_renewing', 'canceled']);

        return view('billing.subscriptions', [
            'activeArea' => 'subscriptions',
            'activeNav' => $status ?? 'all',
            'status' => $status,
            'counts' => $report->counts(),
            'subscriptions' => $report->list($status),
            'unresolvedRetirements' => $this->unresolvedRetirements($retirements),
        ]);
    }

    public function dunning(SubscriptionReport $report): View
    {
        return view('billing.dunning', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'dunning',
            'counts' => $report->counts(),
            'retries' => $report->dunning(),
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
