<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on `created_at` for the idempotency-key prune pass (SEC-2 / L1). The scheduled
 * `billing:prune-idempotency` deletes both expired completed records and stale, never-
 * completed claims by age; the index keeps those range deletes off a full-table scan as the
 * table grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index('created_at', 'idempotency_keys_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex('idempotency_keys_created_at_index');
        });
    }
};
