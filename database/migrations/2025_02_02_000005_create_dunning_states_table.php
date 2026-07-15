<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable backing for the engine's dunning-state store: how many reminders have gone
 * out for an account and when the last one did. Absence of a row reads as a fresh
 * slate, so an account that has never been dunned starts clean. Persisting this makes
 * the notice cadence and the minimum-notice-before-suspension gate survive across
 * scheduled `billing:dunning` runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_states', function (Blueprint $table): void {
            $table->string('account')->primary();
            $table->unsignedInteger('notices_sent')->default(0);
            $table->timestamp('last_notice_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_states');
    }
};
