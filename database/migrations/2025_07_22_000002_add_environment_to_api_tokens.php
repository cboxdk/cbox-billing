<?php

declare(strict_types=1);

use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise an API token's test/live `mode` into an ENVIRONMENT binding: a token is now scoped
 * to a named {@see Environment} (its `environment` key), and the API middleware pushes
 * that environment onto the ambient context so the request reads/writes only that plane's rows.
 *
 * BC: the existing `mode` column is RETAINED and kept in sync — an existing test token backfills
 * to 'sandbox', a live token to 'production'. Token resolution prefers `environment` and falls
 * back to `mode` when it is null (older rows), so every current token keeps working unchanged.
 *
 * DEPLOY NOTE: additive nullable column, backfilled from `mode`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->string('environment')->nullable()->after('mode')->index();
        });

        DB::table('api_tokens')->where('mode', 'test')->update(['environment' => 'sandbox']);
        DB::table('api_tokens')->where('mode', '!=', 'test')->update(['environment' => 'production']);
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropColumn('environment');
        });
    }
};
