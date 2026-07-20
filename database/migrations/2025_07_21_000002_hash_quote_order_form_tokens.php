<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store only the SHA-256 of a quote's order-form token, never the raw CSPRNG (re-review
 * remediation — the twin of the hosted-session token hardening). The token still travels in the
 * `/quote/{token}` URL the customer opens, but a database dump no longer yields live, replayable
 * order-form tokens: a stolen `quotes` row now carries an irreversible digest, not the credential.
 *
 * Migration shape (flagged for the deploy note — this is NOT a pure add): add `token_hash`,
 * backfill it from the existing plaintext `token`, make it unique, then DROP the plaintext `token`
 * column. The backfill runs in PHP so it is driver-agnostic and lossless — every existing sent
 * quote keeps resolving because its URL still hashes to the stored digest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->after('token');
        });

        // Backfill in PHP: hash each existing plaintext token to its digest. migrate:fresh has no
        // rows so this is a no-op there; a live migration transparently upgrades every row.
        DB::table('quotes')->orderBy('id')->chunkById(500, function ($quotes): void {
            foreach ($quotes as $quote) {
                if (is_string($quote->token) && $quote->token !== '') {
                    DB::table('quotes')->where('id', $quote->id)
                        ->update(['token_hash' => hash('sha256', $quote->token)]);
                }
            }
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->unique('token_hash');
        });

        // Drop the plaintext token (and its unique index) — the digest is now the only copy at rest.
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('token', 128)->nullable()->after('id');
        });

        // The plaintext cannot be recovered from the digest; existing rows get a null token back.
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }
};
