<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give an API token a PLANE. A `mode = test` token authenticates the sandbox: the request it
 * carries resolves to the test plane, sees and writes only `livemode = false` rows, and
 * routes charges through the fake gateway with no real email. Existing tokens backfill to
 * `live`, so nothing already issued changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->string('mode')->default('live')->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }
};
