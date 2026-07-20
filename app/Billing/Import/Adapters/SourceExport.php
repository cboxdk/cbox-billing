<?php

declare(strict_types=1);

namespace App\Billing\Import\Adapters;

/**
 * The raw uploaded export as the importer sees it: a map of resource name → the file's raw
 * bytes. A provider export is a bundle of per-resource files (e.g. `customers`, `products`,
 * `prices`, `subscriptions`, `invoices`); a single combined JSON document whose top-level keys
 * are the resource names is also accepted ({@see fromCombinedJson()}).
 *
 * The raw bytes are kept verbatim (not eagerly decoded) so the commit can re-parse exactly the
 * bytes the dry-run reviewed — stored to disk and rebuilt with {@see fromResources()}.
 */
readonly class SourceExport
{
    /**
     * @param  array<string, string>  $files  resource name → raw file contents (JSON)
     */
    public function __construct(private array $files = []) {}

    /**
     * Build from a map of resource → raw contents (the multi-file upload, or the storage round-
     * trip).
     *
     * @param  array<string, string>  $files
     */
    public static function fromResources(array $files): self
    {
        return new self($files);
    }

    /**
     * Build from one combined JSON document whose top-level keys are resource names, each mapping
     * to an array of that resource's records. Non-array top-level values are ignored (deny-by-
     * default: only well-formed resource arrays become resources).
     */
    public static function fromCombinedJson(string $json): self
    {
        $decoded = json_decode($json, true);
        $files = [];

        if (is_array($decoded)) {
            foreach ($decoded as $resource => $records) {
                if (is_string($resource) && is_array($records)) {
                    $files[$resource] = (string) json_encode($records);
                }
            }
        }

        return new self($files);
    }

    /** Whether the export carries a (non-empty) file for a resource. */
    public function has(string $resource): bool
    {
        return isset($this->files[$resource]) && trim($this->files[$resource]) !== '';
    }

    /**
     * The decoded records of a resource as a list of associative arrays. A missing or malformed
     * resource yields an empty list rather than throwing, so a partial export (only some files
     * provided) imports what it can.
     *
     * @return list<array<string, mixed>>
     */
    public function records(string $resource): array
    {
        if (! $this->has($resource)) {
            return [];
        }

        $decoded = json_decode($this->files[$resource], true);

        if (! is_array($decoded)) {
            return [];
        }

        // Tolerate either a bare array of records or a `{ "data": [ ... ] }` envelope (the shape
        // provider list-API dumps use).
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        $records = [];

        foreach ($decoded as $record) {
            if (is_array($record)) {
                /** @var array<string, mixed> $record */
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The raw resource map, for staging the export to disk between the dry-run and the commit.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->files;
    }
}
