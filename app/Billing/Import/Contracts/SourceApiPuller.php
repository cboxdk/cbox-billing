<?php

declare(strict_types=1);

namespace App\Billing\Import\Contracts;

use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Api\NullSourceApiPuller;
use App\Billing\Import\Enums\ImportSource;

/**
 * The live-credentialed pull SEAM — a documented boundary, not a shipped live client. A
 * host that wants to migrate directly from a provider's API (rather than an uploaded export)
 * binds a real implementation here; it authenticates with the operator's provider API key and
 * returns the SAME {@see SourceExport} an uploaded bundle would, so
 * every downstream stage (adapter parse, dry-run plan, idempotent commit) is identical.
 *
 * The shipped default ({@see NullSourceApiPuller}) is honest: it declares
 * itself unconfigured and refuses, rather than pretending to authenticate. We do NOT ship a
 * fabricated live client with faked auth — the file-based export path is the real, supported one.
 */
interface SourceApiPuller
{
    /** Whether a live pull is configured for `$source` on this deployment. */
    public function isConfigured(ImportSource $source): bool;

    /**
     * Pull the provider's data over its API into an export bundle. Implementations authenticate
     * with the operator-supplied credential. The default refuses with an explanatory exception.
     *
     * @param  array<string, string>  $credentials
     */
    public function pull(ImportSource $source, array $credentials): SourceExport;
}
