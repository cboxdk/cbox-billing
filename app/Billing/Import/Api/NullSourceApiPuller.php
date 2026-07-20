<?php

declare(strict_types=1);

namespace App\Billing\Import\Api;

use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Contracts\SourceApiPuller;
use App\Billing\Import\Enums\ImportSource;
use RuntimeException;

/**
 * The honest default for the live-pull seam: no live client is configured, so every source
 * reports unconfigured and {@see pull()} refuses. This is deliberate — we do not fabricate a
 * live provider API client with faked authentication. The real, supported migration path is the
 * file/dump-based export upload; a host that genuinely wants a live pull binds its own
 * {@see SourceApiPuller} implementation in place of this one.
 */
readonly class NullSourceApiPuller implements SourceApiPuller
{
    public function isConfigured(ImportSource $source): bool
    {
        return false;
    }

    public function pull(ImportSource $source, array $credentials): SourceExport
    {
        throw new RuntimeException(sprintf(
            'No live API pull is configured for %s. The supported migration path is uploading the '
            .'provider export file(s); bind a %s implementation to enable a credentialed live pull.',
            $source->label(),
            SourceApiPuller::class,
        ));
    }
}
