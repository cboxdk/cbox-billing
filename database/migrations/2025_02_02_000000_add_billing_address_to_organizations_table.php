<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The org's place of supply for tax. `billing_country` is an ISO 3166-1 alpha-2 code
 * and `billing_subdivision` an optional sub-federal code (a US state, a Canadian
 * province). Both nullable: an org with no address is invoiced *tax-pending* — the
 * quote shows net prices and an honest reason rather than a wrong number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('billing_country', 2)->nullable()->after('billing_email');
            $table->string('billing_subdivision')->nullable()->after('billing_country');
            $table->string('tax_id')->nullable()->after('billing_subdivision');
            $table->boolean('tax_id_validated')->default(false)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['billing_country', 'billing_subdivision', 'tax_id', 'tax_id_validated']);
        });
    }
};
