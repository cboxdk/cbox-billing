# Security Policy

Cbox Billing moves money and enforces spend limits, so we take security reports
seriously. This policy covers the `cboxdk/cbox-billing` application. Vulnerabilities
in the underlying billing engine belong in the `cboxdk/laravel-billing` repository
instead; issues in a payment-gateway adapter belong in that adapter's repository
(`cboxdk/laravel-billing-stripe`, `cboxdk/laravel-billing-mollie`).

## Reporting a vulnerability

Please report suspected vulnerabilities through **GitHub's Private Vulnerability
Reporting**:

1. Go to the repository's **Security** tab.
2. Choose **Report a vulnerability** to open a private advisory.

This keeps the report confidential between you and the maintainers until a fix is
available. Please do **not** open a public issue for a security problem.

When you report, include what you'd want if you were fixing it: affected
version/commit, a description of the impact, and the steps or a proof-of-concept to
reproduce.

## What to expect

This is a small, actively developed project. We handle reports on a **best-effort**
basis — we don't publish a guaranteed response-time or remediation SLA, and we'd
rather set no promise than one we can't keep. We'll acknowledge valid reports,
work with you on a fix, and credit you if you'd like once any fix is released.

We don't currently operate a bug-bounty program.

## Supported versions

The project is **pre-1.0** and pinned to a pre-1.0 engine (`cboxdk/laravel-billing`).
Fixes land on the latest `main`; we don't backport to older tags. Run a current
checkout.

## Engine-level security posture

Much of the billing correctness surface — the append-only, idempotent ledger,
convergent reconciliation, the three-way enforcement outcome (fail-open on
infrastructure, fail-closed on semantics), preview-equals-charge, and the
currency-lock and forfeiture invariants — lives in the engine. For those
guarantees see the `cboxdk/laravel-billing` package docs and decision records:

- Engine docs: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
- Decision records: <https://github.com/cboxdk/laravel-billing/tree/main/adr>

## Operating securely

Keep `APP_DEBUG=false` in production, terminate TLS in front of the app, and hold
gateway API keys and webhook-signing secrets in the environment (never in git).
Webhook endpoints verify the gateway signature and ingest each event exactly once;
do not disable signature verification. The supply chain is gated in CI —
`composer license-check` (permissive-only), `composer audit`, and a drift-checked
CycloneDX SBOM.
