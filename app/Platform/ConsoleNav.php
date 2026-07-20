<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Console\Kit\Contracts\NavRegistry;

/**
 * The base app's own navigation IA, as the single source that both seeds the shared
 * console-kit {@see NavRegistry} (so an installed plugin can add areas/pages, or extend
 * one of ours, with no edit here) and supplies the app-specific per-page enrichment the
 * socket does not model — the URL-is-state filter `params` and the `key` used for active
 * state and live-count lookup.
 *
 * The registry carries structure (areas, pages, order, feature gate); this class also
 * carries {@see pageMeta()}, the params/key enrichment the render path overlays. That
 * split mirrors the reference console: the socket holds structure, the host keeps its
 * own concerns in the render path.
 */
class ConsoleNav
{
    /**
     * Every area/page of the provider console. `feature` (area- or page-level) is a hard
     * presence gate resolved against the console-kit feature registry; `key`/`params` are
     * this app's own render-path enrichment.
     *
     * @var array<string, array{
     *     label: string,
     *     icon: string,
     *     order: int,
     *     feature: string|null,
     *     pages: list<array{route: string, label: string, key: string, params: array<string, string>, fragment?: string, feature: string|null, order: int}>
     * }>
     */
    private const AREAS = [
        'home' => [
            'label' => 'Home', 'icon' => 'home', 'order' => 10, 'feature' => null,
            'pages' => [
                ['route' => 'billing.dashboard', 'label' => 'Dashboard', 'key' => 'dashboard', 'params' => [], 'feature' => null, 'order' => 10],
            ],
        ],
        'analytics' => [
            'label' => 'Analytics', 'icon' => 'trending-up', 'order' => 15, 'feature' => null,
            'pages' => [
                ['route' => 'analytics.revenue', 'label' => 'Revenue', 'key' => 'revenue', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'analytics.retention', 'label' => 'Retention', 'key' => 'retention', 'params' => [], 'feature' => null, 'order' => 20],
            ],
        ],
        'experiments' => [
            'label' => 'Experiments', 'icon' => 'flask', 'order' => 17, 'feature' => null,
            'pages' => [
                ['route' => 'billing.experiments', 'label' => 'All', 'key' => 'all', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.experiments.create', 'label' => 'New experiment', 'key' => 'new', 'params' => [], 'feature' => null, 'order' => 20],
            ],
        ],
        'data' => [
            'label' => 'Data', 'icon' => 'box', 'order' => 16, 'feature' => null,
            'pages' => [
                ['route' => 'billing.exports', 'label' => 'Exports', 'key' => 'exports', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.exports.warehouse', 'label' => 'Warehouse sinks', 'key' => 'warehouse', 'params' => [], 'feature' => null, 'order' => 20],
                ['route' => 'billing.import', 'label' => 'Import', 'key' => 'import', 'params' => [], 'feature' => null, 'order' => 30],
            ],
        ],
        'subscriptions' => [
            'label' => 'Subscriptions', 'icon' => 'repeat', 'order' => 20, 'feature' => null,
            'pages' => [
                ['route' => 'billing.subscriptions', 'label' => 'All', 'key' => 'all', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.subscriptions', 'label' => 'Active', 'key' => 'active', 'params' => ['status' => 'active'], 'feature' => null, 'order' => 20],
                ['route' => 'billing.subscriptions', 'label' => 'Trials', 'key' => 'trialing', 'params' => ['status' => 'trialing'], 'feature' => null, 'order' => 30],
                ['route' => 'billing.subscriptions', 'label' => 'Past due', 'key' => 'past_due', 'params' => ['status' => 'past_due'], 'feature' => null, 'order' => 40],
                ['route' => 'billing.subscriptions', 'label' => 'Paused', 'key' => 'paused', 'params' => ['status' => 'paused'], 'feature' => null, 'order' => 50],
                ['route' => 'billing.subscriptions', 'label' => 'Non-renewing', 'key' => 'non_renewing', 'params' => ['status' => 'non_renewing'], 'feature' => null, 'order' => 60],
                ['route' => 'billing.subscriptions', 'label' => 'Canceled', 'key' => 'canceled', 'params' => ['status' => 'canceled'], 'feature' => null, 'order' => 70],
                ['route' => 'billing.subscriptions.dunning', 'label' => 'Dunning', 'key' => 'dunning', 'params' => [], 'feature' => null, 'order' => 80],
            ],
        ],
        'quotes' => [
            'label' => 'Quotes', 'icon' => 'receipt', 'order' => 25, 'feature' => null,
            'pages' => [
                ['route' => 'billing.quotes', 'label' => 'All', 'key' => 'all', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.quotes', 'label' => 'Drafts', 'key' => 'draft', 'params' => ['status' => 'draft'], 'feature' => null, 'order' => 20],
                ['route' => 'billing.quotes', 'label' => 'Pending approval', 'key' => 'pending_approval', 'params' => ['status' => 'pending_approval'], 'feature' => null, 'order' => 30],
                ['route' => 'billing.quotes', 'label' => 'Sent', 'key' => 'sent', 'params' => ['status' => 'sent'], 'feature' => null, 'order' => 40],
                ['route' => 'billing.quotes', 'label' => 'Accepted', 'key' => 'accepted', 'params' => ['status' => 'accepted'], 'feature' => null, 'order' => 50],
                ['route' => 'billing.quotes.approvals', 'label' => 'Approval queue', 'key' => 'approvals', 'params' => [], 'feature' => null, 'order' => 60],
                ['route' => 'billing.quotes.create', 'label' => 'New quote', 'key' => 'new', 'params' => [], 'feature' => null, 'order' => 70],
            ],
        ],
        'approvals' => [
            'label' => 'Approvals', 'icon' => 'shield', 'order' => 26, 'feature' => null,
            'pages' => [
                ['route' => 'billing.approvals', 'label' => 'Pending', 'key' => 'pending', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.approvals.mine', 'label' => 'My requests', 'key' => 'mine', 'params' => [], 'feature' => null, 'order' => 20],
            ],
        ],
        'invoices' => [
            'label' => 'Invoices', 'icon' => 'invoice', 'order' => 30, 'feature' => null,
            'pages' => [
                ['route' => 'billing.invoices', 'label' => 'All', 'key' => 'all', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.invoices', 'label' => 'Open', 'key' => 'open', 'params' => ['status' => 'open'], 'feature' => null, 'order' => 20],
                ['route' => 'billing.invoices', 'label' => 'Paid', 'key' => 'paid', 'params' => ['status' => 'paid'], 'feature' => null, 'order' => 30],
                ['route' => 'billing.invoices', 'label' => 'Drafts', 'key' => 'draft', 'params' => ['status' => 'draft'], 'feature' => null, 'order' => 40],
                ['route' => 'billing.credit-notes', 'label' => 'Credit notes', 'key' => 'credit-notes', 'params' => [], 'feature' => null, 'order' => 50],
            ],
        ],
        'usage' => [
            'label' => 'Usage', 'icon' => 'activity', 'order' => 40, 'feature' => null,
            'pages' => [
                ['route' => 'billing.usage', 'label' => 'Usage', 'key' => 'meters', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.meters', 'label' => 'Meters', 'key' => 'meters-manage', 'params' => [], 'feature' => null, 'order' => 20],
            ],
        ],
        'catalog' => [
            'label' => 'Catalog', 'icon' => 'box', 'order' => 50, 'feature' => null,
            'pages' => [
                ['route' => 'billing.catalog', 'label' => 'Catalog', 'key' => 'catalog', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.products', 'label' => 'Products', 'key' => 'products', 'params' => [], 'feature' => null, 'order' => 20],
                ['route' => 'billing.coupons', 'label' => 'Coupons', 'key' => 'coupons', 'params' => [], 'feature' => null, 'order' => 25],
                ['route' => 'billing.pricing', 'label' => 'Plans & pricing', 'key' => 'plans', 'params' => [], 'feature' => null, 'order' => 30],
                ['route' => 'billing.features', 'label' => 'Features', 'key' => 'features', 'params' => [], 'feature' => null, 'order' => 35],
                ['route' => 'billing.pricing-tables', 'label' => 'Pricing tables', 'key' => 'pricing-tables', 'params' => [], 'feature' => null, 'order' => 38],
                ['route' => 'billing.catalog.prices.create', 'label' => 'New price', 'key' => 'price-new', 'params' => [], 'feature' => null, 'order' => 40],
            ],
        ],
        'customers' => [
            'label' => 'Customers', 'icon' => 'building', 'order' => 60, 'feature' => null,
            'pages' => [
                ['route' => 'billing.customers', 'label' => 'Customers', 'key' => 'organizations', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.access-grants', 'label' => 'Access grants', 'key' => 'access-grants', 'params' => [], 'feature' => null, 'order' => 20],
                ['route' => 'billing.tax-exemptions', 'label' => 'Tax exemptions', 'key' => 'tax-exemptions', 'params' => [], 'feature' => null, 'order' => 30],
            ],
        ],
        'licenses' => [
            // The on-prem license issuer is an add-on capability, so the whole area is
            // gated on the `licenses` console-kit feature. The base registers that feature
            // always-on (see ConsoleServiceProvider), so it renders identically here; a
            // stripped deployment can turn it off and the area + routes disappear.
            'label' => 'Licenses', 'icon' => 'key', 'order' => 70, 'feature' => 'licenses',
            'pages' => [
                ['route' => 'billing.licenses', 'label' => 'Issued', 'key' => 'issued', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.licenses.settings', 'label' => 'Distribution', 'key' => 'distribution', 'params' => [], 'feature' => null, 'order' => 20],
            ],
        ],
        'settings' => [
            'label' => 'Settings', 'icon' => 'settings', 'order' => 80, 'feature' => null,
            'pages' => [
                ['route' => 'billing.settings', 'label' => 'Sellers', 'key' => 'sellers', 'params' => ['tab' => 'sellers'], 'fragment' => 'sellers', 'feature' => null, 'order' => 10],
                ['route' => 'billing.settings', 'label' => 'Tax', 'key' => 'tax', 'params' => ['tab' => 'tax'], 'fragment' => 'tax', 'feature' => null, 'order' => 20],
                ['route' => 'billing.settings.gateways', 'label' => 'Payment gateways', 'key' => 'gateways', 'params' => [], 'feature' => null, 'order' => 30],
                ['route' => 'billing.settings', 'label' => 'API tokens', 'key' => 'tokens', 'params' => ['tab' => 'tokens'], 'fragment' => 'tokens', 'feature' => null, 'order' => 40],
                ['route' => 'billing.settings.webhooks', 'label' => 'Webhooks', 'key' => 'webhooks', 'params' => [], 'feature' => null, 'order' => 50],
                ['route' => 'billing.settings.fx', 'label' => 'FX rates', 'key' => 'fx', 'params' => [], 'feature' => null, 'order' => 52],
                ['route' => 'billing.settings.emails', 'label' => 'Emails', 'key' => 'emails', 'params' => [], 'feature' => null, 'order' => 55],
                ['route' => 'billing.test-mode.clocks', 'label' => 'Test clocks', 'key' => 'test-clocks', 'params' => [], 'feature' => null, 'order' => 60],
            ],
        ],
        'audit' => [
            'label' => 'Audit', 'icon' => 'shield', 'order' => 90, 'feature' => null,
            'pages' => [
                ['route' => 'billing.audit', 'label' => 'Audit log', 'key' => 'log', 'params' => [], 'feature' => null, 'order' => 10],
                ['route' => 'billing.audit.gdpr', 'label' => 'GDPR / DSAR', 'key' => 'gdpr', 'params' => [], 'feature' => null, 'order' => 20],
            ],
        ],
    ];

    /** Seed the base app's areas/pages into the shared registry. */
    public static function seed(NavRegistry $nav): void
    {
        foreach (self::AREAS as $key => $area) {
            $navArea = $nav->area($key, $area['label'], $area['icon'], $area['order']);
            $navArea->feature($area['feature']);

            foreach ($area['pages'] as $page) {
                $navArea->page($page['route'], $page['label'], $page['feature'], $page['order']);
            }
        }
    }

    /**
     * The render-path enrichment the socket does not model, keyed by area then page label
     * (labels are unique within an area). A page the registry holds but this map does not —
     * a plugin's own page — falls back to no params and its route as the active-state key.
     *
     * @return array<string, array<string, array{key: string, params: array<string, string>, fragment: string|null}>>
     */
    public static function pageMeta(): array
    {
        $meta = [];

        foreach (self::AREAS as $areaKey => $area) {
            foreach ($area['pages'] as $page) {
                $meta[$areaKey][$page['label']] = [
                    'key' => $page['key'],
                    'params' => $page['params'],
                    'fragment' => self::fragmentOf($page),
                ];
            }
        }

        return $meta;
    }

    /**
     * The optional on-page anchor for a page (the Settings tabs deep-link to it), or null. Kept
     * behind a plain-array boundary so the optional `fragment` key reads uniformly regardless of
     * which pages in the const declare it.
     *
     * @param  array<string, mixed>  $page
     */
    private static function fragmentOf(array $page): ?string
    {
        $fragment = $page['fragment'] ?? null;

        return is_string($fragment) ? $fragment : null;
    }
}
