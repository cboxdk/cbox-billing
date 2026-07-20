<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Approvals\ApprovableActionRegistry;
use App\Billing\Approvals\ApprovalPolicy;
use App\Billing\Approvals\ApprovalQueue;
use App\Billing\Approvals\ApprovalService;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Models\ApprovalRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * The Approvals console — the checker's pending queue and the maker's own request tracker over
 * the general two-person-rule engine. Reading/deciding the queue carries the `approvals:decide`
 * slug (a distinct capability, so the same separation of duties CPQ has); the "my requests" view
 * is open to any operator to track their own submissions. Decisions delegate to the
 * {@see ApprovalService}, which enforces the two-person rule and runs the held action exactly
 * once on approval; every guard refuses server-side and is flashed back.
 */
class ApprovalController extends Controller
{
    public function index(ApprovalQueue $queue, ApprovableActionRegistry $registry): View
    {
        $requests = $queue->pending();

        return view('billing.approvals.index', [
            'activeArea' => 'approvals',
            'activeNav' => 'pending',
            'requests' => $requests,
            'descriptions' => $this->describe(collect($requests->items()), $registry),
        ]);
    }

    public function mine(ApprovalQueue $queue, CurrentUser $current): View
    {
        $user = $current->user();
        $sub = $user !== null ? $user->sub : '';

        return view('billing.approvals.mine', [
            'activeArea' => 'approvals',
            'activeNav' => 'mine',
            'requests' => $queue->forRequester($sub),
        ]);
    }

    public function show(ApprovalRequest $approvalRequest, ApprovableActionRegistry $registry, ApprovalPolicy $policy): View
    {
        $approvalRequest->load('decisions');

        return view('billing.approvals.show', [
            'activeArea' => 'approvals',
            'activeNav' => 'pending',
            'request' => $approvalRequest,
            'description' => $this->describeOne($approvalRequest, $registry),
            'threshold' => $policy->thresholdSummary($approvalRequest->action_type),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest, ApprovalService $service): RedirectResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $service->approve($approvalRequest, $request->filled('note') ? $request->string('note')->toString() : null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $fresh = $approvalRequest->fresh();

        return redirect()->route('billing.approvals')->with('status', $fresh !== null && $fresh->status->isExecuted()
            ? sprintf('Request #%d approved and executed.', $approvalRequest->id)
            : sprintf('Request #%d approved — awaiting further approvals.', $approvalRequest->id));
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest, ApprovalService $service): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $service->reject($approvalRequest, $request->string('note')->toString());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.approvals')
            ->with('status', sprintf('Request #%d rejected — nothing was executed.', $approvalRequest->id));
    }

    public function cancel(ApprovalRequest $approvalRequest, ApprovalService $service): RedirectResponse
    {
        try {
            $service->cancel($approvalRequest);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.approvals.mine')
            ->with('status', sprintf('Request #%d canceled.', $approvalRequest->id));
    }

    /**
     * Best-effort human descriptions for a set of requests, keyed by id. Rebuilding an action
     * can fail if its target was since deleted — those rows simply have no rich description and
     * fall back to their stored fields in the view.
     *
     * @param  Collection<int, ApprovalRequest>  $requests
     * @return array<int, ApprovalDescription>
     */
    private function describe(Collection $requests, ApprovableActionRegistry $registry): array
    {
        $out = [];

        foreach ($requests as $request) {
            $description = $this->describeOne($request, $registry);

            if ($description !== null) {
                $out[$request->id] = $description;
            }
        }

        return $out;
    }

    private function describeOne(ApprovalRequest $request, ApprovableActionRegistry $registry): ?ApprovalDescription
    {
        try {
            return $registry->build($request->action_type, $request->payload)->describe();
        } catch (Throwable) {
            return null;
        }
    }
}
