<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Reporting\SettingsReport;
use Illuminate\Contracts\View\View;

/**
 * The env-driven platform-settings detail pages (Wave 4). Payment gateways are configured by
 * environment variables, not database rows, so this page surfaces their real connection/
 * verification STATUS and the exact env keys + rotation guidance — an honest guided-config view,
 * never a fabricated DB config. Gated `settings:read`. The DB-backed settings (seller entities,
 * API tokens, outbound webhook endpoints) are authored on their own routes.
 */
class SettingsController extends Controller
{
    public function gateways(SettingsReport $report): View
    {
        return view('billing.settings.gateways', [
            'activeArea' => 'settings',
            'activeNav' => 'gateways',
            'gateways' => $report->gatewayGuidance(),
        ]);
    }
}
