<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: a nullable `revoked_at` marker so an operator can revoke an API token from the
 * console without deleting its audit row. The authenticator refuses a revoked token (it
 * filters `whereNull('revoked_at')`), so revocation takes effect immediately while the row —
 * name, scope, last-used — survives for the record. Null = live, the default for every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });
    }
};
