<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * Prunes the request-idempotency store (SEC-2 / L1). Two classes of row are removed:
 *
 *  - COMPLETED records ({@see IdempotencyKey::$response_status} set) older than the retention
 *    window — they can hold a captured 2xx response body, so they are never kept indefinitely;
 *    the window still comfortably covers any realistic client retry.
 *  - STALE CLAIMS (`response_status` null) older than the stale window — a first attempt that
 *    died after claiming the key but before completing leaves a poisoned record that answers
 *    every retry with "already in progress" forever (L1). Reaping it frees the key for a
 *    genuine retry.
 *
 * Registered hourly on the scheduler so a poisoned key is never stuck for long.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'billing:prune-idempotency';

    protected $description = 'Prune expired and stale (never-completed) request-idempotency records.';

    public function handle(Config $config): int
    {
        $now = Carbon::now();

        $retentionHours = $this->intConfig($config, 'billing.idempotency.retention_hours', 72);
        $staleMinutes = $this->intConfig($config, 'billing.idempotency.stale_after_minutes', 60);

        // Expired completed records: keep at least one hour so an in-window retry still replays.
        $completedDeleted = IdempotencyKey::query()
            ->whereNotNull('response_status')
            ->where('created_at', '<', $now->copy()->subHours(max(1, $retentionHours)))
            ->delete();
        $completed = is_int($completedDeleted) ? $completedDeleted : 0;

        // Poisoned claims (L1): a never-completed record older than the longest legitimate
        // request. Its key is otherwise blocked forever.
        $staleDeleted = IdempotencyKey::query()
            ->whereNull('response_status')
            ->where('created_at', '<', $now->copy()->subMinutes(max(1, $staleMinutes)))
            ->delete();
        $stale = is_int($staleDeleted) ? $staleDeleted : 0;

        $this->info(sprintf('Pruned %d expired and %d stale idempotency record%s.', $completed, $stale, ($completed + $stale) === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /** A config value coerced to a positive int, falling back to `$default` when non-numeric. */
    private function intConfig(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
