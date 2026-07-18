<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: a nullable `suspended_at` marker on the billing org. Cbox ID's
 * `organization.suspended` / `organization.reactivated` provisioning webhooks stamp and
 * clear it out-of-band, so the billing side reflects a suspended tenant (access held,
 * not billed) without inventing state. Null = active, the default for every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('tax_id_validated');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('suspended_at');
        });
    }
};
