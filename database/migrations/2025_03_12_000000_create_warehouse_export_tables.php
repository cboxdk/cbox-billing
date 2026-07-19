<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data-export / warehouse-sink control plane. These are OPERATOR configuration and
 * operational-log tables (which plane an export targets is a property of the sink, not of a
 * tenant), so they deliberately carry NO `livemode` column and are not plane-partitioned.
 *
 *  - `warehouse_sinks` — a configured destination: object-store disk/prefix, format, the plane
 *    it exports, the datasets it delivers, the warehouse dialect its load manifests are phrased
 *    for, and the load-side coordinates (external base, schema, stage, credential reference).
 *  - `warehouse_sync_cursors` — the per-(sink, dataset) incremental watermark, so a scheduled
 *    sync stages only rows past the last delivery.
 *  - `warehouse_sync_runs` — the delivery/run log: one row per staged partition with its counts,
 *    bytes, cursor window, staged path, manifest path and status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_sinks', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('warehouse')->default('none');
            $table->string('disk');
            $table->string('prefix')->default('');
            $table->string('format')->default('ndjson');
            $table->boolean('livemode')->default(true);
            $table->json('datasets');
            $table->string('schedule')->nullable();
            $table->string('external_base')->nullable();
            $table->string('target_schema')->nullable();
            $table->string('target_stage')->nullable();
            $table->string('credential')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouse_sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sink_id')->constrained('warehouse_sinks')->cascadeOnDelete();
            $table->string('dataset');
            $table->string('cursor_kind');
            $table->string('cursor_value')->nullable();
            $table->unsignedBigInteger('rows_total')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sink_id', 'dataset']);
        });

        Schema::create('warehouse_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sink_id')->constrained('warehouse_sinks')->cascadeOnDelete();
            $table->string('dataset');
            $table->string('warehouse')->default('none');
            $table->string('format');
            $table->string('sync_mode');
            $table->string('status')->default('pending');
            $table->string('partition_path')->nullable();
            $table->string('manifest_path')->nullable();
            $table->unsignedBigInteger('rows')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('cursor_from')->nullable();
            $table->string('cursor_to')->nullable();
            $table->string('partition_date')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['sink_id', 'dataset']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_sync_runs');
        Schema::dropIfExists('warehouse_sync_cursors');
        Schema::dropIfExists('warehouse_sinks');
    }
};
