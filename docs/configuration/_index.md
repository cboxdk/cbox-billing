---
title: Configuration
description: Configure Cbox Billing — the full environment reference, payment gateways, tax and seller entities, queue/cache/session drivers, and CORS + throttling.
weight: 20
---

# Configuration

Cbox Billing is configured through environment variables read by `config/billing.php`
(the single large billing config), plus the standard Laravel config files and the
companion packages' configs (`services.php`, `cbox-id-client.php`, `cors.php`,
`health.php`, `telemetry.php`).

The guiding principle is **deny-by-default**: unset secrets disable the feature
they gate rather than opening it. No gateway secret means webhooks are refused; no
CORS allow-list means no cross-origin browser access; no license key means the free
tier.

## In this section

| Page | What |
| --- | --- |
| [Environment reference](environment.md) | Every environment variable the app reads, grouped by concern. |
| [Payment gateways](payment-gateways.md) | Stripe, Mollie, and the manual signed-webhook gateway; how the bound gateway is selected. |
| [Tax & seller entities](tax-and-sellers.md) | The selling entities of record, per-entity invoice numbering, and tax registrations. |
| [Queue, cache & session](queue-cache-session.md) | Local `database` drivers vs Redis/Valkey in production, and the durable billing stores. |
| [CORS & throttling](cors-and-throttling.md) | The deny-by-default CORS allow-list and the two-tier per-token rate limits. |

## Where each concern lives

| Concern | Config file | Key env |
| --- | --- | --- |
| Billing knobs | `config/billing.php` | `CBOX_BILLING_*`, `CBOX_LICENSE_*`, `STRIPE_*` |
| Identity (OIDC login) | `config/services.php` → `cbox_id` | `CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_*`, `CBOX_ID_REDIRECT_URI` |
| Federated RBAC manifest | `config/cbox-id-client.php` | `CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_*` |
| CORS | `config/cors.php` | `CORS_ALLOWED_ORIGINS` |
| Health | `config/health.php` | `HEALTH_ENABLED`, `HEALTH_TOKEN` |
| Telemetry | `config/telemetry.php` | `TELEMETRY_ENABLED`, `TELEMETRY_STORE` |

## Related documentation

- [Identity](../identity/_index.md) — Cbox ID + RBAC.
- [Deployment → Production checklist](../deployment/production-checklist.md)
- Engine config keys: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
