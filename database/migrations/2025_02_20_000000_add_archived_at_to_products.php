<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-deactivate (archive) for products. A product that still groups plans cannot be
 * hard-deleted without orphaning catalog history, so the console archives it instead:
 * `archived_at` hides it from the "new plan" product picker and marks it archived in the
 * catalog, while its plans (and every subscriber grandfathered onto them) are untouched —
 * the engine keeps resolving the same per-plan catalog. Additive and nullable, so every
 * existing product stays live (null = active).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
