---
title: Security
description: The security posture of Cbox Billing — deny-by-default everywhere, secrets handling, webhook signature verification, tenant scoping, the honest documented seams, and how to report a vulnerability.
weight: 90
---

# Security

Cbox Billing moves money and enforces spend limits, so its security model is
deliberate and, where a boundary is not yet fully closed, **honest about it**. This
section covers the app-layer posture, the seams we document plainly, and the reporting
process.

## In this section

| Page | What |
| --- | --- |
| [Posture](posture.md) | Deny-by-default, secrets handling, webhook verification, tenant scoping, and the supply-chain gate. |
| [Documented seams](documented-seams.md) | The boundaries that are stubbed or best-effort and what that means for you. |
| [Reporting a vulnerability](reporting.md) | GitHub Private Vulnerability Reporting; the honest best-effort stance. |

## The app / engine boundary

Much of the billing **correctness** surface — the append-only idempotent ledger,
convergent reconciliation, the three-way enforcement outcome (fail-open on infra,
fail-closed on semantics), preview-equals-charge, and the currency-lock and
forfeiture invariants — lives in the **engine**. For those guarantees see the
`cboxdk/laravel-billing` package docs and its decision records. This section covers
the **application** layer: auth, secrets, webhooks, tenant isolation, and the app's
own seams.

- Engine security posture: <https://github.com/cboxdk/laravel-billing/tree/main/docs> → security
- Engine decision records: <https://github.com/cboxdk/laravel-billing/tree/main/adr>

## Related documentation

- [Configuration → CORS & throttling](../configuration/cors-and-throttling.md)
- [API → Authentication](../api/authentication.md)
- [Deployment → Operations](../deployment/operations.md)
