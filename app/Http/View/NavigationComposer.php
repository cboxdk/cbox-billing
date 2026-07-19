<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Billing\Licensing\LicenseReport;
use App\Billing\Reporting\InvoiceReport;
use App\Billing\Reporting\SettingsReport;
use App\Billing\Support\SubscriptionStanding;
use App\Models\CboxIdAccessGrant;
use App\Models\Coupon;
use App\Models\CreditNote;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\PaymentRetry;
use App\Models\Plan;
use App\Models\Product;
use App\Platform\ConsoleNav;
use Cbox\Console\Kit\Contracts\FeatureRegistry;
use Cbox\Console\Kit\Contracts\NavRegistry;
use Illuminate\Contracts\View\View;

/**
 * Builds the shell's navigation from the shared console-kit {@see NavRegistry} — so an
 * installed plugin's areas/pages appear with no edit to this app — and overlays the two
 * things the socket does not model: the app-specific `params`/`key` enrichment
 * ({@see ConsoleNav::pageMeta()}) and the live, database-derived counts. A page (or area)
 * carrying a console-kit `feature` is dropped unless that feature is active, so the
 * presence gate hides inactive capabilities from the nav.
 *
 * The output shape is the same `$navAreas` the layout has always consumed, so the shell
 * renders identically for the base app's own pages.
 */
readonly class NavigationComposer
{
    public function __construct(
        private NavRegistry $nav,
        private FeatureRegistry $features,
        private InvoiceReport $invoices,
        private SettingsReport $settings,
        private LicenseReport $licenses,
    ) {}

    public function compose(View $view): void
    {
        $counts = $this->counts();
        $subsAll = ($counts['subscriptions']['all'] ?? 0);
        $subsCanceled = ($counts['subscriptions']['canceled'] ?? 0);
        $meta = ConsoleNav::pageMeta();

        $areas = [];

        foreach ($this->nav->areas() as $area) {
            // Area-level presence gate: a whole area a plugin gated on an inactive feature.
            if ($area->feature !== null && ! $this->features->active($area->feature)) {
                continue;
            }

            $areaMeta = $meta[$area->key] ?? [];
            $areaCounts = $counts[$area->key] ?? [];
            $nav = [];

            foreach ($area->pages() as $page) {
                // Page-level presence gate.
                if ($page->feature !== null && ! $this->features->active($page->feature)) {
                    continue;
                }

                // App-specific enrichment; a plugin's own page falls back to its route.
                $key = $areaMeta[$page->label]['key'] ?? $page->route;
                $params = $areaMeta[$page->label]['params'] ?? [];
                $fragment = $areaMeta[$page->label]['fragment'] ?? null;
                $count = $areaCounts[$key] ?? null;

                $nav[] = [
                    'label' => $page->label,
                    'key' => $key,
                    'route' => $page->route,
                    'params' => $params,
                    'fragment' => $fragment,
                    'count' => $count !== null ? (string) $count : null,
                ];
            }

            if ($nav === []) {
                continue; // every page hidden by a gate — drop the empty area.
            }

            $rendered = [
                'label' => $area->label,
                'icon' => $area->icon ?? 'box',
                'route' => $nav[0]['route'], // the rail icon links to the first visible page.
                'nav' => $nav,
            ];

            if ($area->key === 'subscriptions') {
                $rendered['badge'] = (string) ($subsAll - $subsCanceled);
            }

            $areas[$area->key] = $rendered;
        }

        $view->with('navAreas', $areas);
    }

    /**
     * The live, database-derived counts, keyed by area then page key.
     *
     * @return array<string, array<string, int>>
     */
    private function counts(): array
    {
        $subs = SubscriptionStanding::counts();
        $invoiceCounts = $this->invoices->counts();

        return [
            'subscriptions' => [
                'all' => $subs['all'],
                'active' => $subs['active'],
                'trialing' => $subs['trialing'],
                'past_due' => $subs['past_due'],
                'paused' => $subs['paused'],
                'non_renewing' => $subs['non_renewing'],
                'canceled' => $subs['canceled'],
                'dunning' => PaymentRetry::query()->where('status', PaymentRetry::STATUS_RETRYING)->count(),
            ],
            'invoices' => [
                'all' => $invoiceCounts['all'],
                'open' => $invoiceCounts['open'],
                'paid' => $invoiceCounts['paid'],
                'draft' => $invoiceCounts['draft'],
                'credit-notes' => CreditNote::query()->count(),
            ],
            'usage' => [
                'meters' => Meter::query()->count(),
                'meters-manage' => Meter::query()->count(),
            ],
            'catalog' => [
                'products' => Product::query()->count(),
                'coupons' => Coupon::query()->count(),
                'plans' => Plan::query()->count(),
            ],
            'customers' => [
                'organizations' => Organization::query()->count(),
                'access-grants' => CboxIdAccessGrant::query()->count(),
            ],
            'licenses' => ['issued' => $this->licenses->counts()['all']],
            'settings' => [
                'sellers' => count($this->settings->sellers()),
                'tax' => count($this->settings->taxRegistrations()),
                'gateways' => count($this->settings->gateways()),
                'tokens' => $this->settings->apiTokens()->count(),
                'webhooks' => 1,
            ],
        ];
    }
}
