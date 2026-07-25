<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freezes the seller's legal identity onto each document at issue.
 *
 * Invoice and credit-note PDFs rendered the seller identity LIVE from the register, so editing a
 * seller entity retroactively changed the legal identity — legal name, registration number, and
 * `establishment`, which is the tax jurisdiction shown — on documents already issued to customers.
 * Under EU Directive 2006/112/EC the content of an issued invoice is fixed; re-rendering must
 * reproduce it as of issue.
 *
 * The exposure grew when document rendering moved from config to the register. Config is
 * deploy-gated, so a retroactive change took a release; the register is editable by any operator
 * with the console permission, so a routine correction to a legal name silently rewrote history.
 *
 * NULLABLE, and read with a live-register fallback. Rows issued before this column exist keep
 * rendering exactly as they do today — there is nothing truthful to backfill for them, and
 * inventing a snapshot from the CURRENT register would be the very thing this prevents.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'credit_notes'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'seller_identity')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                // JSON rather than a column per field: this is an opaque, point-in-time record
                // that is only ever written whole and read whole, never queried or joined on. The
                // seller REFERENCE stays in the existing `seller` column, which is what the delete
                // guard and reporting use.
                $blueprint->json('seller_identity')->nullable()->after('seller');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'credit_notes'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'seller_identity')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('seller_identity');
                });
            }
        }
    }
};
