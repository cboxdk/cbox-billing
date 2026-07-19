<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive audit column (SEC-1): the Cbox ID `sub` of the operator who minted the token. An
 * operator-scoped (org-null) token acts for ANY org, so recording WHO minted it turns the
 * takeover-vector surface into an accountable one — the mint is now attributable to a named
 * operator, not anonymous. Nullable: CLI-issued and pre-existing tokens carry no console
 * subject, and the column never affects authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->string('created_by_sub')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropColumn('created_by_sub');
        });
    }
};
