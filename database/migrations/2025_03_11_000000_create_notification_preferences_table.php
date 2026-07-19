<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization opt-out ledger for the OPTIONAL lifecycle notifications (renewal
 * reminder, trial-ending, receipts). Absence of a row means the default (opted in), so an
 * org only ever accrues a row when a customer changes a toggle in the portal. The
 * mandatory/legal mails (invoice issued, dunning, subscription changed) are never keyed
 * here — they ignore preferences and always send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('event_type');
            $table->boolean('opted_in')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
