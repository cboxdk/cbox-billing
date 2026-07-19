<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR erasure marker on organizations. Right-to-be-forgotten cannot hard-delete an org
 * that holds legally-retained financial documents (invoices, credit notes, the ledger), so
 * erasure PSEUDONYMIZES the org's PII in place and records that it happened here:
 * `erased_at` (when) and `erased_by_sub` (which operator). A non-null `erased_at` is what the
 * console renders as "PII erased — financial records retained (de-identified)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('erased_at')->nullable()->after('suspended_at');
            $table->string('erased_by_sub')->nullable()->after('erased_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['erased_at', 'erased_by_sub']);
        });
    }
};
