<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable record of every minted on-prem license. The primary key is the license
 * id (the artifact's `lid` claim), so a revocation keyed on that id links straight to
 * its row. `key` is the signed, offline-verifiable artifact itself; the decoded copy of
 * its contents (plan, entitlements, limits, bindings, window) rides alongside so the
 * issuer console can list, renew and revoke without re-parsing the JWT.
 *
 * A deployment holds at most one live license — a renewal is a fresh id under the SAME
 * `deployment_id`, so "current for a deployment" is the most recently created row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_licenses', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('customer_id')->index();
            $table->string('deployment_id')->index();
            $table->string('plan');
            $table->json('entitlements');
            $table->json('limits');
            $table->string('licensed_domain')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('not_before');
            $table->timestamp('expires_at');
            $table->longText('key');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_licenses');
    }
};
