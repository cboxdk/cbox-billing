<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's preferred language for transactional email (additive, nullable). It is the
 * TOP layer of the locale resolution chain: a customer with a locale on file is emailed in
 * it; an org with none falls through to the selling entity's default locale, then the app
 * fallback. Nullable so a single-locale deployment never has to set it — resolution never
 * dead-ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('locale', 12)->nullable()->after('billing_email');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
