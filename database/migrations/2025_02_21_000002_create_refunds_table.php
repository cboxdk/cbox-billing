<?php

declare(strict_types=1);

use Cbox\Billing\Refund\Contracts\RefundRepository;
use Cbox\Billing\Refund\ValueObjects\Refund;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable {@see RefundRepository} store — what makes a
 * refund idempotent (its `refund_id` short-circuits a retry BEFORE a credit-note number
 * is drawn) and bounds the cumulative refunded amount to what was charged (the sum of
 * `gross_minor` per invoice is the over-refund cap). Separate from the display-facing
 * `credit_notes` table so the cap/idempotency invariant is independent of the record
 * surface, and on the same connection as the ledger so both commit together.
 *
 * `gross_minor` is a positive magnitude; the enough of the outcome to reconstruct the
 * engine {@see Refund} for an idempotent replay is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->string('refund_id')->primary();
            $table->string('invoice_number')->index();
            $table->string('credit_note_number');
            $table->string('account')->index();
            $table->string('seller');
            $table->string('currency', 3);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('gross_minor');
            $table->string('reason');
            $table->string('ledger_transaction_id');
            $table->string('grant_reversal_id')->nullable();
            $table->string('kind');
            $table->string('gateway_status');
            $table->string('gateway_reference')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
