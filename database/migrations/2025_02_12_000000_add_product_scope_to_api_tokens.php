<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bind an API token to one product (nullable = unscoped/legacy). On a shared
     * instance billing several products, a product-bound token sees and sells only
     * that product's plans — the isolation seam for consuming platforms.
     */
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('organization_id')
                ->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
