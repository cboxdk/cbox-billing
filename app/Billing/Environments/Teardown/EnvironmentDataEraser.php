<?php

declare(strict_types=1);

namespace App\Billing\Environments\Teardown;

use App\Billing\Mode\EnvironmentScope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes a plane's rows from the environment-scoped tables, category by category. Every delete is
 * a plain `DELETE … WHERE environment = ?` on the base query builder — it bypasses the model
 * {@see EnvironmentScope} (so it targets the NAMED plane, not the ambient one)
 * and is guarded by a schema check so a not-yet-migrated or plugin-added table is skipped rather
 * than assumed. Returns the per-table deleted-row counts for the caller to report/audit.
 *
 * This is the low-level primitive; {@see EnvironmentResetter}
 * (transactional only) and {@see EnvironmentDestroyer} (config +
 * transactional) compose it, each inside its own transaction.
 */
readonly class EnvironmentDataEraser
{
    public function __construct(
        private ConnectionInterface $connection,
        private EnvironmentDataMap $map,
    ) {}

    /**
     * Wipe the transactional/tenant rows for a plane (the runtime book), keeping its config.
     *
     * @return array<string, int> table → deleted-row count (only tables with deletions)
     */
    public function wipeTransactional(string $environmentKey): array
    {
        return $this->wipeTables($this->map->transactionalTables(), $environmentKey);
    }

    /**
     * Wipe every environment-scoped row for a plane (config AND transactional) — the full teardown.
     *
     * @return array<string, int> table → deleted-row count (only tables with deletions)
     */
    public function wipeAll(string $environmentKey): array
    {
        return $this->wipeTables($this->map->allTables(), $environmentKey);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function wipeTables(array $tables, string $environmentKey): array
    {
        $deleted = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'environment')) {
                continue;
            }

            $count = $this->connection->table($table)->where('environment', $environmentKey)->delete();

            if ($count > 0) {
                $deleted[$table] = $count;
            }
        }

        return $deleted;
    }
}
