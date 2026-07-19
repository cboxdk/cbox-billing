<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tamper-evident operator audit trail. Unlike the per-customer activity view (which
 * DERIVES a feed from existing records), this table is the immutable, append-only record of
 * every operator MUTATION performed through the console — who did what, to which resource,
 * with the before/after where meaningful.
 *
 * Two protections make it tamper-EVIDENT (honestly: evident, not tamper-PROOF — an operator
 * with direct DB and application-secret access could still rewrite the whole chain):
 *
 *  1. A hash chain. Each row carries `prev_hash` (the previous row's `hash`) and its own
 *     `hash` = H(prev_hash · canonical(payload)). `audit:verify` walks the chain and reports
 *     the first row whose stored hash no longer matches its recomputed one, so any in-place
 *     edit of a single row surfaces as a break.
 *  2. A DB-level append-only guard. BEFORE UPDATE / BEFORE DELETE triggers refuse any
 *     mutation of a persisted row (per driver: sqlite RAISE(ABORT), Postgres RAISE EXCEPTION,
 *     MySQL SIGNAL). Rows can only be inserted; INSERT is never blocked.
 *
 * `sequence` is the monotonic chain position (genesis = 1). `metadata` holds the typed
 * before/after diff as JSON — NEVER a secret (token plaintext, license key, certificate
 * document): the recorder logs the fact plus a reference, never the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sequence')->unique();
            $table->timestamp('occurred_at');
            $table->string('actor_sub')->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_ip', 45)->nullable();
            $table->string('action')->index();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('organization_id')->nullable()->index();
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->boolean('livemode')->default(true)->index();
            $table->char('prev_hash', 64);
            $table->char('hash', 64)->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id']);
            $table->index(['action', 'id']);
        });

        $this->installAppendOnlyGuard();
    }

    public function down(): void
    {
        $this->dropAppendOnlyGuard();
        Schema::dropIfExists('operator_audit_events');
    }

    /**
     * Install the BEFORE UPDATE / BEFORE DELETE triggers that make the table append-only at the
     * database layer. The three supported drivers each express the same refusal natively.
     */
    private function installAppendOnlyGuard(): void
    {
        $message = 'operator_audit_events is append-only (tamper-evident audit trail)';

        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->sqliteGuard($message),
            'pgsql' => $this->pgsqlGuard($message),
            'mysql', 'mariadb' => $this->mysqlGuard($message),
            default => null,
        };
    }

    private function dropAppendOnlyGuard(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite', 'mysql', 'mariadb' => $this->dropTriggers(),
            'pgsql' => $this->dropPgsqlGuard(),
            default => null,
        };
    }

    private function sqliteGuard(string $message): void
    {
        foreach (['update', 'delete'] as $op) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER operator_audit_events_block_%1$s BEFORE %2$s ON operator_audit_events '
                .'BEGIN SELECT RAISE(ABORT, %3$s); END;',
                $op,
                strtoupper($op),
                $this->quote($message),
            ));
        }
    }

    private function pgsqlGuard(string $message): void
    {
        DB::unprepared(sprintf(
            'CREATE OR REPLACE FUNCTION operator_audit_events_immutable() RETURNS trigger AS $$ '
            .'BEGIN RAISE EXCEPTION %s; END; $$ LANGUAGE plpgsql;',
            $this->quote($message),
        ));

        foreach (['update', 'delete'] as $op) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER operator_audit_events_block_%1$s BEFORE %2$s ON operator_audit_events '
                .'FOR EACH ROW EXECUTE FUNCTION operator_audit_events_immutable();',
                $op,
                strtoupper($op),
            ));
        }
    }

    private function mysqlGuard(string $message): void
    {
        foreach (['update', 'delete'] as $op) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER operator_audit_events_block_%1$s BEFORE %2$s ON operator_audit_events '
                .'FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = %3$s;',
                $op,
                strtoupper($op),
                $this->quote($message),
            ));
        }
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS operator_audit_events_block_update;');
        DB::unprepared('DROP TRIGGER IF EXISTS operator_audit_events_block_delete;');
    }

    private function dropPgsqlGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS operator_audit_events_block_update ON operator_audit_events;');
        DB::unprepared('DROP TRIGGER IF EXISTS operator_audit_events_block_delete ON operator_audit_events;');
        DB::unprepared('DROP FUNCTION IF EXISTS operator_audit_events_immutable();');
    }

    /** Single-quote a literal for inline trigger DDL (no bindings are possible in DDL). */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
