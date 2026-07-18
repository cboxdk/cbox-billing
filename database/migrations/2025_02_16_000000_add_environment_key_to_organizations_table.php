<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, backward-compatible: record each billing org's HOME ENVIRONMENT.
 *
 * Cbox ID is environment-scoped — each org lives inside exactly one environment — but the
 * org id stays the tenant primary key (an org belongs to exactly one environment, so the
 * id is already unique; no breaking composite key). `environment_key` is the plane billing
 * groups the org under. It is nullable and every existing row is backfilled to the single
 * configured default, so single-environment deployments are unaffected. Per-environment
 * separation lights up only once Cbox ID emits an `environment` claim to stamp here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('environment_key')->nullable()->after('id');
        });

        $default = config('cbox-id-client.environment_default', 'default');

        DB::table('organizations')
            ->whereNull('environment_key')
            ->update(['environment_key' => is_string($default) && $default !== '' ? $default : 'default']);

        Schema::table('organizations', function (Blueprint $table): void {
            $table->index('environment_key');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['environment_key']);
            $table->dropColumn('environment_key');
        });
    }
};
