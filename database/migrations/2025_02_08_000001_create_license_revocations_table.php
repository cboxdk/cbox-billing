<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issuer-side set of revoked license ids. The revocation publisher reads it to cut a
 * freshly-signed revocation list a verifier deployment consults as the last step of
 * verification, so a revoked license is refused offline until the next list is pulled.
 *
 * The `license_id` is the primary key, so revoking is idempotent — revoking an
 * already-revoked id updates the same row rather than duplicating it. `reason` is a free
 * operator note; it never rides in the signed list (which carries only ids).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_revocations', function (Blueprint $table): void {
            $table->string('license_id')->primary();
            $table->timestamp('revoked_at');
            $table->string('reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_revocations');
    }
};
