<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens the enforcement HTTP API authenticates against. A token is scoped to a
 * single organization (the SDK embedded in that org's app) or, when `organization_id`
 * is null, is an operator token allowed to act for any org. Only the SHA-256 `hash` is
 * stored — the plaintext is shown once at issue and never persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('organization_id')->nullable();
            $table->string('hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
