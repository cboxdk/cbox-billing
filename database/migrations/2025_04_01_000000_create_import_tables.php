<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotent import / migration substrate (#import) — three additive tables that let a
 * seller bring their existing catalog, customers, subscriptions and historical invoices over
 * from another provider (Stripe / Chargebee / Recurly) WITHOUT switching cost. Nothing on the
 * metering or billing hot paths reads these; they are a migration ledger.
 *
 *  1. `import_runs` — one operator import operation. A run is first PLANNED (a dry-run: parse
 *     the uploaded export, resolve every row against the ledger, surface conflicts + the
 *     proposed plan mapping — no writes), then COMMITTED (the same resolution, executed through
 *     the real domain services). It carries the source, the resolved plane (`livemode` — a run
 *     imports into exactly one plane, so test data never leaks into live), the raw-export
 *     storage path the commit re-parses, the (operator-adjustable) plan mapping, and the
 *     per-entity/outcome counts + surfaced conflicts.
 *  2. `import_run_entries` — the per-row import log: one row per source record with its outcome
 *     (created / updated / skipped / failed / conflict) and the app model it resolved to, so the
 *     source→app id mapping is browsable per run.
 *  3. `import_source_refs` — the idempotency + provenance ledger: a stable
 *     (`source`, `source_type`, `source_id`) → (`app_type`, `app_id`) mapping, unique per plane.
 *     A re-run of the same export matches here and updates/skips rather than duplicating; the
 *     mapping is also the audit trail of where every migrated record came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            // The provider the export came from (stripe / chargebee / recurly).
            $table->string('source', 32);
            // planned (dry-run resolved, awaiting commit) → running → completed / failed.
            $table->string('status', 24)->default('planned');
            // Whether this run has only been planned (true) or actually committed (false).
            $table->boolean('dry_run')->default(true);
            // The plane the run imports into — one run writes exactly one plane.
            $table->boolean('livemode')->default(true);
            // The operator who ran it (from the console session), for the audit trail.
            $table->string('actor_sub')->nullable();
            $table->string('actor_name')->nullable();
            // Where the raw uploaded export is staged so the commit re-parses the SAME bytes
            // the dry-run planned against (deny-by-default: a commit never re-parses a
            // different file than the one the operator reviewed).
            $table->string('export_path', 2048)->nullable();
            // The operator plan mapping applied (source plan id → app plan id) as a JSON map.
            $table->json('plan_mapping')->nullable();
            // The per-entity/outcome summary counts, and the surfaced conflicts, as JSON.
            $table->json('counts')->nullable();
            $table->json('conflicts')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'livemode']);
            $table->index('status');
        });

        Schema::create('import_run_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();
            // The normalized entity kind + the provider's id for the record.
            $table->string('source_type', 32);
            $table->string('source_id');
            // created / updated / skipped / failed / conflict.
            $table->string('outcome', 16);
            // The app model this row resolved to (null for a conflict/failed row).
            $table->string('app_type', 32)->nullable();
            $table->string('app_id')->nullable();
            $table->string('message', 1024)->nullable();
            $table->boolean('livemode')->default(true);
            $table->timestamps();

            $table->index(['import_run_id', 'source_type']);
            $table->index('outcome');
        });

        Schema::create('import_source_refs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('source_type', 32);
            $table->string('source_id');
            $table->string('app_type', 32);
            $table->string('app_id');
            $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->boolean('livemode')->default(true);
            $table->timestamps();

            // The idempotency key: one provider record maps to exactly one app record per plane.
            $table->unique(['source', 'source_type', 'source_id', 'livemode'], 'import_source_refs_natural_key');
            $table->index(['app_type', 'app_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_source_refs');
        Schema::dropIfExists('import_run_entries');
        Schema::dropIfExists('import_runs');
    }
};
