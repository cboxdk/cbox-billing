<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Audit\AuditChainVerifier;
use App\Billing\Audit\AuditLogQuery;
use App\Billing\Export\DataExporter;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Mode\BillingContext;
use App\Models\OperatorAuditEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Audit log console area — thin HTTP over {@see AuditLogQuery} (search/filter/paginate),
 * {@see AuditChainVerifier} (the chain-status indicator) and {@see DataExporter} (CSV/NDJSON
 * export, reusing the data-export system). The trail is read-only here; the only writes to it are
 * the audit events the recording seam appends for the operator's own mutations.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogQuery $query, AuditChainVerifier $verifier): View
    {
        $filters = $this->filters($request);

        return view('billing.audit.index', [
            'activeArea' => 'audit',
            'activeNav' => 'log',
            'events' => $query->paginate($filters),
            'filters' => $filters,
            'actions' => $query->distinctActions(),
            'chain' => $verifier->verify(),
        ]);
    }

    public function show(OperatorAuditEvent $event, BillingContext $context): View
    {
        // The audit trail is a single global, append-only hash chain across every plane, so the
        // model carries NO environment global scope — an unscoped route-model binding would let an
        // event from one plane be read from another. The console view is per-plane (like the index),
        // so assert the event belongs to the current environment; anything else is a 404
        // (deny-by-default: never confirm an out-of-plane event exists).
        abort_unless($event->getAttribute('environment') === $context->environmentKey(), 404);

        return view('billing.audit.show', [
            'activeArea' => 'audit',
            'activeNav' => 'log',
            'event' => $event,
        ]);
    }

    /** Stream the trail as CSV or NDJSON, scoped to the current plane. */
    public function export(Request $request, DataExporter $exporter, BillingContext $context): StreamedResponse
    {
        $format = ExportFormat::parse(is_string($request->query('format')) ? $request->query('format') : null);

        return $exporter->download('audit_events', $format, ExportQuery::plane($context->environmentKey(), $context->livemode()));
    }

    /**
     * The normalized, whitelisted filter set from the request query string.
     *
     * @return array{q: ?string, action: ?string, actor: ?string, target_type: ?string, organization_id: ?string, from: ?string, to: ?string}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => $this->query($request, 'q'),
            'action' => $this->query($request, 'action'),
            'actor' => $this->query($request, 'actor'),
            'target_type' => $this->query($request, 'target_type'),
            'organization_id' => $this->query($request, 'org'),
            'from' => $this->query($request, 'from'),
            'to' => $this->query($request, 'to'),
        ];
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
