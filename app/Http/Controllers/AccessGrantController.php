<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Reporting\AccessGrantReport;
use App\Models\CboxIdAccessGrant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The access-grant (RBAC mirror) viewer — thin HTTP over {@see AccessGrantReport}. Lists the
 * local {@see CboxIdAccessGrant} projection the provisioning webhooks maintain:
 * which Cbox ID subjects hold which role on which billing org. Strictly READ-ONLY — Cbox ID
 * owns assignment, so this surface never mutates; the page makes the projection nature clear.
 * Gated `customers:read`.
 */
class AccessGrantController extends Controller
{
    public function index(Request $request, AccessGrantReport $report): View
    {
        $q = $request->query('q');
        $search = is_string($q) && trim($q) !== '' ? trim($q) : null;

        return view('billing.access-grants', [
            'activeArea' => 'customers',
            'activeNav' => 'access-grants',
            'search' => $search,
            'grants' => $report->paginate($search),
            'total' => $report->total(),
        ]);
    }
}
