<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable hosted-session store (ADR-0009 Path A). A checkout- or portal-session is
 * addressed by an opaque, non-guessable `token` carried in the hosted page URL — the
 * token authorizes the page, not the provider auth gate. `payment_reference` is the
 * reference the gateway settlement webhook later carries, joining the client-side intent
 * to the exactly-once activation. `expires_at` bounds the token's life; `status` moves
 * pending → complete (on the settled webhook) and is stamped `expired` once past its TTL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique();
            $table->string('organization_id');
            $table->string('type', 16);
            $table->string('plan_key')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('return_url');
            $table->string('payment_reference')->nullable()->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_sessions');
    }
};
