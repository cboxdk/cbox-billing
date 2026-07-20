<?php

declare(strict_types=1);

namespace App\Billing\Import\Contracts;

use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\Normalized\NormalizedDataset;

/**
 * Parses one provider's EXPORT files into the app's normalized model. This is the credential-
 * free migration path: the seller downloads their data export from the provider and uploads it;
 * the adapter maps that provider's schema — its field names, its unit convention (minor units vs
 * decimal major units), its date format, its status/discount/duration vocabulary — into the
 * normalized shape the importer understands. It performs NO writes and holds NO credentials; it
 * is a pure function of the uploaded bytes.
 *
 * A live-credentialed pull is a separate seam ({@see SourceApiPuller}) that produces the same
 * {@see SourceExport}, so the adapter is unchanged whether the data came from a file or an API.
 */
interface SourceAdapter
{
    /** The provider this adapter maps. */
    public function source(): ImportSource;

    /** A short human label for the console picker. */
    public function label(): string;

    /**
     * The resource files this adapter reads from the export bundle, each with a one-line note of
     * what it provides — surfaced in the console + docs so the operator knows what to upload.
     *
     * @return array<string, string> resource name → description
     */
    public function expectedFiles(): array;

    /** Parse the uploaded export into the normalized dataset. Pure; no writes, no network. */
    public function parse(SourceExport $export): NormalizedDataset;
}
