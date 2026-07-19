<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decline-awareness on the smart-retry row. Adaptive dunning classifies each failed charge's
 * gateway decline code into a recovery category and drives the schedule off it; those two
 * facts are stamped here so the console, the category-selected email and the recovery
 * analytics all read the same source of truth. `save_offer_*` records the retention offer
 * presented when the charge entered dunning (the deep-integration seam), for the console and
 * the dunning email to surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_retries', function (Blueprint $table): void {
            // The canonical decline-code token (e.g. `insufficient_funds`) of the latest failure.
            $table->string('decline_code')->nullable()->after('status');
            // The recovery category the code classified into (drives the adaptive schedule).
            $table->string('decline_category')->nullable()->after('decline_code');
            // The retention save-offer presented on entry to dunning (deep integration).
            $table->string('save_offer_key')->nullable()->after('decline_category');
            $table->string('save_offer_label')->nullable()->after('save_offer_key');

            // Recovery analytics slice on category + terminal state.
            $table->index(['decline_category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_retries', function (Blueprint $table): void {
            $table->dropIndex(['decline_category', 'status']);
            $table->dropColumn(['decline_code', 'decline_category', 'save_offer_key', 'save_offer_label']);
        });
    }
};
