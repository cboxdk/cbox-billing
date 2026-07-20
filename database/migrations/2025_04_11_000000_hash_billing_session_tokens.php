<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store only the SHA-256 of a hosted-session token, never the raw 48-char CSPRNG (P2). The
 * token still travels in the hosted page URL to the customer, but a database dump no longer
 * yields live, replayable tokens: a stolen `billing_sessions` row now carries an irreversible
 * digest, not the credential itself.
 *
 * Migration shape (flagged for the deploy note — this is NOT a pure add): add the `token_hash`
 * column, backfill it from the existing plaintext `token`, make it unique, then DROP the
 * plaintext `token` column. The backfill runs in PHP so it is driver-agnostic (SQLite has no
 * native SHA-256) and lossless — every existing pending token keeps resolving because its URL
 * still hashes to the stored digest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->after('token');
        });

        // Backfill in PHP: hash each existing plaintext token to its digest. migrate:fresh has
        // no rows so this is a no-op there; a live migration transparently upgrades every row.
        DB::table('billing_sessions')->orderBy('id')->chunkById(500, function ($sessions): void {
            foreach ($sessions as $session) {
                if (is_string($session->token) && $session->token !== '') {
                    DB::table('billing_sessions')
                        ->where('id', $session->id)
                        ->update(['token_hash' => hash('sha256', $session->token)]);
                }
            }
        });

        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->unique('token_hash');
        });

        // Drop the plaintext token (and its unique index) — the digest is now the only copy at
        // rest. This is the destructive-by-design step: the whole point is that plaintext tokens
        // no longer live in the table.
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }

    public function down(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->string('token', 64)->nullable()->after('id');
        });

        // The plaintext cannot be recovered from the digest; existing rows get a null token back.
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }
};
