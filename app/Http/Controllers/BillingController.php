<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\BillingMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Thin HTTP layer over the billing engine — resolves the metrics service and maps
 * the result onto the Cbox app-shell views. No business logic lives here.
 */
class BillingController extends Controller
{
    public function dashboard(BillingMetrics $metrics): View
    {
        return view('billing.dashboard', [
            'activeArea' => 'home',
            'activeNav' => 'dashboard',
            'metrics' => $metrics,
            'revenue' => $metrics->revenue(),
            'invoices' => $metrics->invoices(),
        ]);
    }

    public function subscriptions(BillingMetrics $metrics): View
    {
        return view('billing.subscriptions', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'active',
            'subscriptions' => $metrics->subscriptions(),
        ]);
    }

    public function invoices(BillingMetrics $metrics): View
    {
        return view('billing.invoices', [
            'activeArea' => 'invoices',
            'activeNav' => 'all',
            'invoices' => $metrics->invoices(),
        ]);
    }

    /** Not-yet-built sections render the shell's empty state with the right context. */
    public function section(Request $request, string $area): View
    {
        $areas = config('cbox_nav.areas');
        abort_unless(isset($areas[$area]), 404);

        return view('billing.generic', [
            'activeArea' => $area,
            'activeNav' => $areas[$area]['nav'][0]['key'] ?? null,
            'title' => $areas[$area]['label'],
        ]);
    }
}
