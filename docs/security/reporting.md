---
title: Reporting a vulnerability
description: How to report a security issue in Cbox Billing — GitHub Private Vulnerability Reporting — and the honest best-effort stance on response times, supported versions, and where engine or adapter issues belong.
weight: 93
---

# Reporting a vulnerability

Cbox Billing moves money and enforces spend limits, so security reports are taken
seriously. This mirrors the repository's `SECURITY.md`.

## How to report

Report suspected vulnerabilities through **GitHub's Private Vulnerability Reporting**:

1. Go to the repository's **Security** tab.
2. Choose **Report a vulnerability** to open a private advisory.

This keeps the report confidential between you and the maintainers until a fix is
available. Please do **not** open a public issue for a security problem.

Include what you would want if you were fixing it: the affected version/commit, the
impact, and steps or a proof-of-concept to reproduce.

## What to expect (honest best-effort)

This is a small, actively developed project. Reports are handled on a **best-effort**
basis. There is **no** published guaranteed response-time or remediation SLA — we
would rather set no promise than one we cannot keep. Valid reports are acknowledged,
worked on collaboratively, and credited (if you would like) once a fix is released.
There is **no** bug-bounty program.

We do not claim any security certification, conformance, or audit that has not been
performed, and we do not operate a dedicated security mailbox — Private Vulnerability
Reporting is the channel.

## Supported versions

The project is **pre-1.0** and pinned to a pre-1.0 engine. Fixes land on the latest
`main`; older tags are not backported. Run a current checkout.

## Where an issue belongs

- A vulnerability in the **billing engine** belongs in
  [`cboxdk/laravel-billing`](https://github.com/cboxdk/laravel-billing).
- A vulnerability in a **payment-gateway adapter** belongs in that adapter's repo
  (e.g. `cboxdk/laravel-billing-stripe`, `cboxdk/laravel-billing-mollie`).
- A vulnerability in **this application** (auth, webhooks, tenant scoping, the console,
  the APIs) belongs here in `cboxdk/cbox-billing`.

## Related documentation

- [Posture](posture.md)
- [Documented seams](documented-seams.md)
- Engine security: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
