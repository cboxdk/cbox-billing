<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key for subscription period invoicing (H4). A renewal/period invoice is
 * uniquely identified by its subscription and billing period `[period_start, period_end]`;
 * a unique index on that triple makes issuance idempotent, so an at-least-once job retry or
 * a concurrent re-run returns the existing invoice rather than minting a second legal
 * number and double-charging.
 *
 * `subscription_id` also stamps ad-hoc invoices (a mid-cycle proration charge) with the
 * subscription they belong to, but those leave `period_start`/`period_end` null — the
 * unique index treats null period columns as distinct (SQL NULLs never collide), so
 * multiple proration invoices for one subscription are allowed while its period invoice for
 * a given cycle is at most one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('organization_id');
            $table->timestamp('period_start')->nullable()->after('subscription_id');
            $table->timestamp('period_end')->nullable()->after('period_start');

            $table->unique(['subscription_id', 'period_start', 'period_end'], 'invoices_subscription_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_subscription_period_unique');
            $table->dropColumn(['subscription_id', 'period_start', 'period_end']);
        });
    }
};
