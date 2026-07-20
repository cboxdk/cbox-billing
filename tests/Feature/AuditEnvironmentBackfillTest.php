<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Finding 2 (P1) — the generalize-livemode→environment migration must backfill
 * `operator_audit_events.environment` WITHOUT tripping the table's append-only BEFORE UPDATE guard.
 * A fresh migrate has no audit rows, so the gate missed it; a real deploy that already holds sandbox
 * audit rows would abort the whole migration on the bulk UPDATE. The fix lifts ONLY the update guard
 * for the one-time mirror backfill, then restores it.
 */
class AuditEnvironmentBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2025_07_22_000001_generalize_livemode_to_environment.php';

    /** Insert a pre-existing sandbox audit row that has NOT yet had its environment mirror backfilled. */
    private function seedUnbackfilledSandboxRow(int $sequence): void
    {
        // INSERT is always allowed by the append-only guard (only UPDATE/DELETE are refused).
        DB::table('operator_audit_events')->insert([
            'sequence' => $sequence,
            'occurred_at' => now(),
            'actor_sub' => 'operator|1',
            'action' => 'test.pre_existing',
            'summary' => 'a sandbox audit row that predates the environment backfill',
            'livemode' => false,
            'environment' => 'production', // the column default — not yet flipped to sandbox
            'prev_hash' => str_repeat('0', 64),
            'hash' => str_repeat((string) $sequence, 64),
            'created_at' => now(),
        ]);
    }

    public function test_a_naive_backfill_update_is_blocked_by_the_append_only_guard(): void
    {
        $this->seedUnbackfilledSandboxRow(9001);

        // The generic backfill the migration runs on every other table would abort here — proving the
        // trigger is live and why the audit table needs the trigger-safe path.
        $this->expectException(QueryException::class);

        DB::table('operator_audit_events')->where('livemode', false)->update(['environment' => 'sandbox']);
    }

    public function test_the_trigger_safe_backfill_flips_sandbox_rows_and_restores_the_guard(): void
    {
        $this->seedUnbackfilledSandboxRow(9002);

        $migration = require base_path(self::MIGRATION);
        (new ReflectionMethod($migration, 'backfillAuditEnvironment'))->invoke($migration);

        // The sandbox row's environment mirror is now correct — with no trigger abort.
        $this->assertSame('sandbox', DB::table('operator_audit_events')->where('sequence', 9002)->value('environment'));

        // The BEFORE UPDATE guard was restored: the trail is append-only again.
        $this->expectException(QueryException::class);
        DB::table('operator_audit_events')->where('sequence', 9002)->update(['summary' => 'tampered']);
    }
}
