---
title: The API-pull seam
description: Why the live-credentialed API pull is a documented seam rather than a shipped client, the honest no-op default, and how a host binds a real puller that reuses the whole import pipeline.
weight: 50
---

# The API-pull seam

The supported, shipped migration path is **file-based**: upload the provider's export. A
live-credentialed pull — authenticating with the provider's API and pulling the data directly — is
provided as a **seam**, not as a shipped client.

## Why a seam, not a shipped client

We do not ship a live provider API client with faked authentication. A fabricated client that
"works" in a demo but cannot actually authenticate would be dishonest about what the product does.
The file-based path is real and credential-free; the live pull is an explicit extension point a
host can implement with real credentials on their own infrastructure.

## The contract

```php
interface SourceApiPuller
{
    public function isConfigured(ImportSource $source): bool;

    /** @param array<string, string> $credentials */
    public function pull(ImportSource $source, array $credentials): SourceExport;
}
```

A puller returns the **same `SourceExport`** an uploaded bundle produces, so every downstream stage
— the adapter's `parse()`, the dry-run plan, the idempotent commit — is **identical** whether the
data came from a file or an API. The adapters are unchanged.

## The shipped default is honest

The bound default, `NullSourceApiPuller`, reports every source as **unconfigured** and refuses
`pull()` with an explanatory exception. There is no hidden live client.

## Binding a real puller

A host that wants a live pull binds its own implementation in a service provider:

```php
$this->app->bind(
    \App\Billing\Import\Contracts\SourceApiPuller::class,
    \App\Billing\Import\Api\StripeLiveApiPuller::class, // your implementation
);
```

Its `pull()` authenticates with the operator-supplied credential, fetches the provider's objects,
and assembles a `SourceExport` (a resource → JSON map). From there the existing pipeline takes over
— nothing else changes.
