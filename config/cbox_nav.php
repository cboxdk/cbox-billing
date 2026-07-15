<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cbox · Billing — navigation information architecture
|--------------------------------------------------------------------------
|
| The two-tier product-shell IA (rail area -> contextual subnav), following the
| Cbox design system's app-shell pattern. Rail order is fixed; each area's
| `nav` entries render as the tier-2 subnav. `route` is a named route or null
| (renders the empty-state screen for not-yet-built sections).
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
            'badge' => '182',
            'nav' => [
                ['label' => 'Active', 'key' => 'active', 'route' => 'billing.subscriptions', 'count' => '182'],
                ['label' => 'Trials', 'key' => 'trials', 'route' => null, 'count' => '14'],
                ['label' => 'Past due', 'key' => 'past-due', 'route' => null, 'count' => '3'],
                ['label' => 'Canceled', 'key' => 'canceled', 'route' => null, 'count' => null],
            ],
        ],
        'invoices' => [
            'label' => 'Invoices',
            'icon' => 'invoice',
            'route' => 'billing.invoices',
            'nav' => [
                ['label' => 'All', 'key' => 'all', 'route' => 'billing.invoices', 'count' => null],
                ['label' => 'Open', 'key' => 'open', 'route' => null, 'count' => '7'],
                ['label' => 'Paid', 'key' => 'paid', 'route' => null, 'count' => null],
                ['label' => 'Drafts', 'key' => 'drafts', 'route' => null, 'count' => '2'],
                ['label' => 'Credit notes', 'key' => 'credit-notes', 'route' => null, 'count' => null],
            ],
        ],
        'usage' => [
            'label' => 'Usage',
            'icon' => 'activity',
            'route' => 'billing.section',
            'nav' => [
                ['label' => 'Meters', 'key' => 'meters', 'route' => null, 'count' => '6'],
                ['label' => 'Events', 'key' => 'events', 'route' => null, 'count' => null],
                ['label' => 'Alerts', 'key' => 'alerts', 'route' => null, 'count' => '1'],
            ],
        ],
        'catalog' => [
            'label' => 'Catalog',
            'icon' => 'box',
            'route' => 'billing.section',
            'nav' => [
                ['label' => 'Products', 'key' => 'products', 'route' => null, 'count' => '4'],
                ['label' => 'Plans', 'key' => 'plans', 'route' => null, 'count' => '11'],
                ['label' => 'Prices', 'key' => 'prices', 'route' => null, 'count' => null],
                ['label' => 'Coupons', 'key' => 'coupons', 'route' => null, 'count' => '3'],
            ],
        ],
        'customers' => [
            'label' => 'Customers',
            'icon' => 'building',
            'route' => 'billing.section',
            'nav' => [
                ['label' => 'Organizations', 'key' => 'organizations', 'route' => null, 'count' => '58'],
                ['label' => 'Wallets', 'key' => 'wallets', 'route' => null, 'count' => null],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'icon' => 'settings',
            'route' => 'billing.section',
            'nav' => [
                ['label' => 'General', 'key' => 'general', 'route' => null, 'count' => null],
                ['label' => 'Sellers', 'key' => 'sellers', 'route' => null, 'count' => '2'],
                ['label' => 'Tax', 'key' => 'tax', 'route' => null, 'count' => null],
                ['label' => 'Payment gateways', 'key' => 'gateways', 'route' => null, 'count' => '2'],
                ['label' => 'Webhooks', 'key' => 'webhooks', 'route' => null, 'count' => '4'],
            ],
        ],
    ],
];
