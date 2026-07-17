---
title: The plugin model
description: How a plugin lights up its navigation, UI, feature gates, and migrations through the console-kit socket and Laravel auto-discovery — with zero edits to the base app.
weight: 61
---

# The plugin model

A commercial plugin adds whole console areas, pages, dashboard cards, and database
tables to Cbox Billing **without a single edit to the app**. It does this through two
mechanisms the base app already wires: **Laravel auto-discovery** and the
**console-kit socket**.

## The console-kit socket

The app integrates with [`cboxdk/laravel-console-kit`](https://github.com/cboxdk/laravel-console-kit)
in `ConsoleServiceProvider`:

- It binds the `CurrentContext` to the app's auth (`ConsoleCurrentContext`), so a
  plugin can resolve the current org/user without depending on the app's OIDC claim
  shape.
- It seeds the base navigation IA (`ConsoleNav`) into the **shared nav registry**.
- It registers the base app's own features (e.g. `licenses`, always-on).

Because the console shell renders from the **registry** (via `NavigationComposer`),
an installed plugin adds areas/pages/slots/dashboard cards purely by registering them
in the same registry at boot. Nothing in the base app changes.

## What a plugin registers

On install, a plugin's own service provider (discovered automatically) can:

| Registers | Through | Result |
| --- | --- | --- |
| Nav areas & pages | The shared `NavRegistry` | New console sections appear. |
| A console-kit **feature** | The feature registry | A hard presence gate for its pages/routes. |
| Migrations | `loadMigrationsFrom` | Its tables are picked up by `php artisan migrate`. |
| UI / dashboard slots | Console-kit slots | Cards and panels render in the shell. |
| Capability checks | The `CapabilityGate` | Its features stay locked until entitled. |

Deny-by-default in the socket means a feature a plugin never registers is simply
absent — there is no implicit surface.

## The nav IA is one source of truth

`App\Platform\ConsoleNav` holds the base app's areas/pages once, and both seeds the
registry and supplies the app-specific render-path enrichment (the URL-is-state
`params` and the active-state `key`). A plugin page the enrichment map does not know
falls back to sensible defaults — so a plugin's pages render correctly without the
app knowing about them in advance.

## Migrations compose automatically

Because each plugin loads its own migrations, a single `php artisan migrate` (or
`composer deploy`) applies the app's migrations **and** every installed plugin's —
reseller rollup tables, revrec deferred-revenue schedules, connectors sync ledger,
tax-plus prepared filings — alongside the engine's own ledger/event-log tables.

## Related documentation

- [Capability gating](capability-gating.md)
- [Commercial plugins](commercial-plugins.md)
- [Getting started → Console tour](../getting-started/console-tour.md)
- Console socket: <https://github.com/cboxdk/laravel-console-kit>
