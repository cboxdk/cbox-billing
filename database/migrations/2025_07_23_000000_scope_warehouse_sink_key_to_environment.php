<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the warehouse-sink `key` uniqueness to the PLANE. A sink is operator-infra of one billing
 * ENVIRONMENT (it carries an `environment` column and is environment-scoped), but its `key` was
 * still GLOBALLY unique — so two planes could not each own a sink named e.g. `analytics`, and a
 * name taken in production silently blocked every sandbox. Move the unique key from `(key)` to
 * `(key, environment)`, so the name is unique WITHIN a plane and independent across planes.
 *
 * DEPLOY NOTE: an index swap on `warehouse_sinks` (drop the single-column unique, add the composite
 * unique). Additive to the data (no column/row change). A pre-existing deployment cannot have had
 * two same-key sinks (the old global unique forbade it), so the new composite unique is always
 * satisfiable — no data cleanup is required before it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_sinks', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_sinks', function (Blueprint $table): void {
            $table->dropUnique(['key', 'environment']);
            $table->unique(['key']);
        });
    }
};
