<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Models\Meter;
use Cbox\Billing\Metering\Enums\Aggregation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

/**
 * Create / edit / archive / delete a {@see Meter}. A meter's `aggregation` is the engine
 * {@see Aggregation} the metering pipeline collapses its raw usage events with, so the
 * console-authored choice is the one the engine bills on. Removal is guarded: a meter an
 * entitlement references, or that has recorded usage, is archived (soft-deactivated) so
 * its historical policy keeps resolving — only a never-referenced meter is hard-deleted.
 */
readonly class MeterAuthoring
{
    private const USAGE_TABLE = 'billing_usage_events';

    public function __construct(
        private ConnectionInterface $db,
        private SchemaBuilder $schema,
    ) {}

    /**
     * @param  array{key: string, name: string, unit: string, aggregation: Aggregation, display: ?string}  $data
     */
    public function create(array $data): Meter
    {
        $this->assertKeyUnique($data['key'], null);

        return Meter::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'unit' => $data['unit'],
            'aggregation' => $data['aggregation'],
            'display' => $data['display'],
        ]);
    }

    /**
     * @param  array{key: string, name: string, unit: string, aggregation: Aggregation, display: ?string}  $data
     */
    public function update(Meter $meter, array $data): Meter
    {
        $this->assertKeyUnique($data['key'], $meter->id);

        $meter->update([
            'key' => $data['key'],
            'name' => $data['name'],
            'unit' => $data['unit'],
            'aggregation' => $data['aggregation'],
            'display' => $data['display'],
        ]);

        return $meter;
    }

    /** Soft-deactivate the meter; existing entitlements keep resolving its policy. */
    public function archive(Meter $meter): void
    {
        $meter->forceFill(['archived_at' => now()])->save();
    }

    /** Reinstate an archived meter. */
    public function unarchive(Meter $meter): void
    {
        $meter->forceFill(['archived_at' => null])->save();
    }

    /**
     * Hard-delete a meter — refused while an entitlement references it or it has recorded
     * usage, so no plan policy or usage history is orphaned. Archive instead.
     */
    public function delete(Meter $meter): void
    {
        $entitlements = $meter->entitlements()->count();

        if ($entitlements > 0) {
            throw CatalogActionDenied::meterReferenced($meter->name, $entitlements);
        }

        if ($this->hasUsage($meter)) {
            throw CatalogActionDenied::meterHasUsage($meter->name);
        }

        $meter->delete();
    }

    private function hasUsage(Meter $meter): bool
    {
        if (! $this->schema->hasTable(self::USAGE_TABLE)) {
            return false;
        }

        return $this->db->table(self::USAGE_TABLE)->where('meter', $meter->key)->exists();
    }

    private function assertKeyUnique(string $key, ?int $ignoreId): void
    {
        $exists = Meter::query()
            ->where('key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw CatalogActionDenied::duplicateKey($key);
        }
    }
}
