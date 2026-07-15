<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Billing\Reporting\InvoiceReport;
use App\Billing\Reporting\SettingsReport;
use App\Billing\Support\SubscriptionStanding;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;

/**
 * Overlays the live, database-derived counts onto the config-defined navigation IA so
 * the shell's rail badge and tier-2 counts always show real data — the config file owns
 * the structure, this composer owns the numbers.
 */
readonly class NavigationComposer
{
    public function __construct(
        private Config $config,
        private InvoiceReport $invoices,
        private SettingsReport $settings,
    ) {}

    public function compose(View $view): void
    {
        $areas = $this->config->get('cbox_nav.areas');

        if (! is_array($areas)) {
            return;
        }

        $subs = SubscriptionStanding::counts();
        $invoiceCounts = $this->invoices->counts();

        $counts = [
            'subscriptions' => [
                'all' => $subs['all'],
                'active' => $subs['active'],
                'trialing' => $subs['trialing'],
                'past_due' => $subs['past_due'],
                'canceled' => $subs['canceled'],
            ],
            'invoices' => [
                'all' => $invoiceCounts['all'],
                'open' => $invoiceCounts['open'],
                'paid' => $invoiceCounts['paid'],
                'draft' => $invoiceCounts['draft'],
            ],
            'usage' => ['meters' => Meter::query()->count()],
            'catalog' => [
                'products' => Product::query()->count(),
                'plans' => Plan::query()->count(),
            ],
            'customers' => ['organizations' => Organization::query()->count()],
            'settings' => [
                'sellers' => count($this->settings->sellers()),
                'tax' => count($this->settings->taxRegistrations()),
                'gateways' => count($this->settings->gateways()),
                'tokens' => $this->settings->apiTokens()->count(),
                'webhooks' => 1,
            ],
        ];

        if (isset($areas['subscriptions']) && is_array($areas['subscriptions'])) {
            $areas['subscriptions']['badge'] = (string) ($subs['all'] - $subs['canceled']);
        }

        foreach ($areas as $areaKey => $area) {
            if (! is_array($area) || ! isset($area['nav']) || ! is_array($area['nav'])) {
                continue;
            }

            $areaCounts = is_string($areaKey) ? ($counts[$areaKey] ?? []) : [];
            $nav = $area['nav'];

            foreach ($nav as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $key = $item['key'] ?? null;
                $value = is_string($key) ? ($areaCounts[$key] ?? null) : null;
                $item['count'] = $value !== null ? (string) $value : null;
                $nav[$index] = $item;
            }

            $area['nav'] = $nav;
            $areas[$areaKey] = $area;
        }

        $view->with('navAreas', $areas);
    }
}
