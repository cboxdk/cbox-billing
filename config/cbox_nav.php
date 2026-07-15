<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cbox · Billing — navigation information architecture
|--------------------------------------------------------------------------
|
| The two-tier product-shell IA (rail area -> contextual subnav), following the
| Cbox design system's app-shell pattern. Rail order is fixed; each area's
| `nav` entries render as the tier-2 subnav. `route` is a named route (null renders a
| disabled entry) and `params` are appended for URL-is-state filtering.
|
| `count` / `badge` here are structural defaults only — the live counts are computed
| from real data and overlaid by App\Http\View\NavigationComposer, so what the shell
| renders is always the database truth.
|
*/

return [
    'areas' => [
        'home' => [
            'label' => 'Home',
            'icon' => 'home',
            'route' => 'billing.dashboard',
            'nav' => [
                ['label' => 'Dashboard', 'key' => 'dashboard', 'route' => 'billing.dashboard', 'count' => null],
            ],
        ],
        'subscriptions' => [
            'label' => 'Subscriptions',
            'icon' => 'repeat',
            'route' => 'billing.subscriptions',
            'nav' => [
                ['label' => 'All', 'key' => 'all', 'route' => 'billing.subscriptions', 'count' => null],
                ['label' => 'Active', 'key' => 'active', 'route' => 'billing.subscriptions', 'params' => ['status' => 'active'], 'count' => null],
                ['label' => 'Trials', 'key' => 'trialing', 'route' => 'billing.subscriptions', 'params' => ['status' => 'trialing'], 'count' => null],
                ['label' => 'Past due', 'key' => 'past_due', 'route' => 'billing.subscriptions', 'params' => ['status' => 'past_due'], 'count' => null],
                ['label' => 'Canceled', 'key' => 'canceled', 'route' => 'billing.subscriptions', 'params' => ['status' => 'canceled'], 'count' => null],
            ],
        ],
        'invoices' => [
            'label' => 'Invoices',
            'icon' => 'invoice',
            'route' => 'billing.invoices',
            'nav' => [
                ['label' => 'All', 'key' => 'all', 'route' => 'billing.invoices', 'count' => null],
                ['label' => 'Open', 'key' => 'open', 'route' => 'billing.invoices', 'params' => ['status' => 'open'], 'count' => null],
                ['label' => 'Paid', 'key' => 'paid', 'route' => 'billing.invoices', 'params' => ['status' => 'paid'], 'count' => null],
                ['label' => 'Drafts', 'key' => 'draft', 'route' => 'billing.invoices', 'params' => ['status' => 'draft'], 'count' => null],
            ],
        ],
        'usage' => [
            'label' => 'Usage',
            'icon' => 'activity',
            'route' => 'billing.usage',
            'nav' => [
                ['label' => 'Meters', 'key' => 'meters', 'route' => 'billing.usage', 'count' => null],
            ],
        ],
        'catalog' => [
            'label' => 'Catalog',
            'icon' => 'box',
            'route' => 'billing.catalog',
            'nav' => [
                ['label' => 'Products', 'key' => 'products', 'route' => 'billing.catalog', 'count' => null],
                ['label' => 'Plans', 'key' => 'plans', 'route' => 'billing.catalog', 'count' => null],
            ],
        ],
        'customers' => [
            'label' => 'Customers',
            'icon' => 'building',
            'route' => 'billing.customers',
            'nav' => [
                ['label' => 'Organizations', 'key' => 'organizations', 'route' => 'billing.customers', 'count' => null],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'icon' => 'settings',
            'route' => 'billing.settings',
            'nav' => [
                ['label' => 'Sellers', 'key' => 'sellers', 'route' => 'billing.settings', 'count' => null],
                ['label' => 'Tax', 'key' => 'tax', 'route' => 'billing.settings', 'count' => null],
                ['label' => 'Payment gateways', 'key' => 'gateways', 'route' => 'billing.settings', 'count' => null],
                ['label' => 'API tokens', 'key' => 'tokens', 'route' => 'billing.settings', 'count' => null],
                ['label' => 'Webhooks', 'key' => 'webhooks', 'route' => 'billing.settings', 'count' => null],
            ],
        ],
    ],
];
