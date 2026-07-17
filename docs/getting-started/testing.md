---
title: Running the tests
description: The composer qa verification gate — Pint, PHPStan, PHPUnit, the permissive-license check, and composer audit — plus the SBOM drift check.
weight: 13
---

# Running the tests

Cbox Billing ships a single verification gate that mirrors CI. Run it before you
commit.

```bash
composer qa
```

`qa` runs five checks in order; the first failure stops the run:

| Step | Command | What it enforces |
| --- | --- | --- |
| `lint` | `pint --test` | Code style (Laravel Pint). Reports drift; changes nothing. |
| `analyse` | `phpstan analyse` | Static analysis at the level in `phpstan.neon`. |
| `test` | `php artisan test` | The PHPUnit suite (with a `config:clear` first). |
| `license-check` | `php bin/check-licenses.php` | Every production dependency is permissively licensed (MIT/BSD/Apache/ISC/0BSD). |
| `composer audit` | — | No known advisories in the dependency tree. |

## Running the pieces individually

```bash
composer test          # the PHPUnit suite
composer lint          # pint --test (add `vendor/bin/pint` to auto-fix)
composer analyse       # phpstan
composer license-check # bin/check-licenses.php
```

The test suite is configured by `phpunit.xml`. Tests run against an isolated test
environment; you do not need a running server or the queue worker.

## The SBOM

```bash
composer sbom
```

Regenerates the CycloneDX software bill of materials (`sbom.json`). CI fails on
drift, so regenerate and commit it whenever dependencies change. This is part of
the supply-chain posture described in [Security](../security/posture.md).

## What "the code gate is untouched" means

This documentation set adds only Markdown under `docs/`. No PHP, config, or route
was changed, so `vendor/bin/pint --test` continues to pass exactly as before — the
docs build is orthogonal to the code gate.

## Related documentation

- [Installation](installation.md)
- [Security → Posture](../security/posture.md)
