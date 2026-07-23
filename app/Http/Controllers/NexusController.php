<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Nexus\NexusReporter;
use App\Billing\Nexus\UsStates;
use App\Billing\Seller\SellerCatalog;
use App\Models\SellerExternalSales;
use App\Models\SellerPhysicalPresence;
use App\Models\SellerTaxRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The US economic-nexus console for the default selling entity — thin HTTP over the
 * {@see NexusReporter} (engine + dataset thresholds) and the operator-declared registers.
 * It shows the per-state standing (Triggered / Approaching / Registered / Below) the engine
 * computes, and lets an operator declare where the seller has physical presence (with an
 * optional start/end window) and record sales made through OTHER channels — the inputs the
 * engine cannot infer. Reads are gated `nexus:read`, writes `nexus:manage`.
 */
class NexusController extends Controller
{
    public function __construct(
        private readonly NexusReporter $reporter,
        private readonly SellerCatalog $sellers,
    ) {}

    /** `GET` — the per-state standing plus the presence + external-sales registers. */
    public function index(): View
    {
        $sellerId = $this->sellers->default()->id;
        $report = $this->reporter->report();

        // Order the standing table by urgency: act-now first, watch next, then handled/quiet.
        $rank = ['triggered' => 0, 'approaching' => 1, 'registered' => 2, 'below' => 3];
        $evaluations = $report->evaluations;
        usort($evaluations, static fn ($a, $b): int => [$rank[$a->status->value], $a->state->value] <=> [$rank[$b->status->value], $b->state->value]);

        return view('billing.nexus', [
            'activeArea' => 'nexus',
            'activeNav' => 'overview',
            'evaluations' => $evaluations,
            'soleSalesChannel' => $this->reporter->soleSalesChannel(),
            'triggeredCount' => count($report->triggered()),
            'approachingCount' => count($report->approaching()),
            'registeredCount' => count($report->registered()),
            'presence' => SellerPhysicalPresence::query()
                ->where('seller_entity_id', $sellerId)->orderBy('subdivision')->get(),
            'externalSales' => SellerExternalSales::query()
                ->where('seller_entity_id', $sellerId)->orderBy('subdivision')->orderByDesc('period_year')->get(),
            'registeredStates' => SellerTaxRegistration::query()
                ->where('seller_entity_id', $sellerId)->where('country', 'US')
                ->whereNotNull('subdivision')->pluck('subdivision')->all(),
            'states' => UsStates::all(),
            'currentYear' => (int) Carbon::now()->year,
        ]);
    }

    /** `POST` — declare physical presence in a state, with an optional effective window. */
    public function storePresence(Request $request): RedirectResponse
    {
        $request->validate([
            'subdivision' => ['required', 'string', 'in:'.implode(',', UsStates::codes())],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $subdivision = $request->string('subdivision')->toString();

        SellerPhysicalPresence::query()->create([
            'seller_entity_id' => $this->sellers->default()->id,
            'subdivision' => $subdivision,
            'effective_from' => $request->filled('effective_from') ? $request->string('effective_from')->toString() : null,
            'effective_to' => $request->filled('effective_to') ? $request->string('effective_to')->toString() : null,
        ]);

        return redirect()->route('billing.nexus')
            ->with('status', sprintf('Physical presence in %s recorded.', UsStates::name($subdivision)));
    }

    /** `DELETE` — end/remove a physical-presence declaration. */
    public function destroyPresence(SellerPhysicalPresence $presence): RedirectResponse
    {
        $this->ownedByDefaultSeller($presence->seller_entity_id);
        $state = $presence->subdivision;
        $presence->delete();

        return redirect()->route('billing.nexus')
            ->with('status', sprintf('Physical presence in %s removed.', UsStates::name($state)));
    }

    /** `POST` — record sales into a state made through another channel, for a calendar year. */
    public function storeExternalSales(Request $request): RedirectResponse
    {
        $request->validate([
            'subdivision' => ['required', 'string', 'in:'.implode(',', UsStates::codes())],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            // Bounded so a fat-fingered figure cannot overflow the unsigned columns
            // (transactions: unsignedInteger; sales_dollars: unsignedBigInteger).
            'sales_dollars' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'transactions' => ['required', 'integer', 'min:0', 'max:4000000000'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $subdivision = $request->string('subdivision')->toString();
        $year = $request->integer('period_year');

        SellerExternalSales::query()->create([
            'seller_entity_id' => $this->sellers->default()->id,
            'subdivision' => $subdivision,
            'period_year' => $year,
            'sales_dollars' => $request->integer('sales_dollars'),
            'transactions' => $request->integer('transactions'),
            'source' => $request->filled('source') ? $request->string('source')->toString() : null,
        ]);

        return redirect()->route('billing.nexus')
            ->with('status', sprintf('External-channel sales for %s (%d) recorded.', UsStates::name($subdivision), $year));
    }

    /** `DELETE` — remove an external-channel sales entry. */
    public function destroyExternalSales(SellerExternalSales $external): RedirectResponse
    {
        $this->ownedByDefaultSeller($external->seller_entity_id);
        $external->delete();

        return redirect()->route('billing.nexus')->with('status', 'External-channel sales entry removed.');
    }

    /**
     * Deny-by-default: a row bound from the route must belong to the current default seller
     * (the environment scope already confines it to this plane). A mismatch is a 404.
     */
    private function ownedByDefaultSeller(string $sellerEntityId): void
    {
        if ($sellerEntityId !== $this->sellers->default()->id) {
            throw new NotFoundHttpException('Not found.');
        }
    }
}
