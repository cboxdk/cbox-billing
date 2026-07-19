<?php

declare(strict_types=1);

use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Refund\Contracts\Refunder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The persisted credit-note record surface (Wave 3). A credit note is the legal reversal
 * of (part of) an issued invoice — a refund or an adjustment issues one through the
 * engine's {@see Refunder}, which fires
 * {@see CreditNoteIssued}; the app listens and persists the row here.
 *
 * Stored amounts are POSITIVE magnitudes in integer minor units of `currency` (the
 * document's sign is the reversal — the console renders them as credited/returned). The
 * `number` is the credit note's own legal number, drawn off the seller's credit-note
 * sequence, never an invoice number — and its uniqueness is what makes persistence
 * idempotent, so a re-delivered event never writes the note twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('invoice_number')->index();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('organization_id')->index();
            $table->string('seller');
            $table->string('currency', 3);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('gross_minor');
            $table->string('reason');
            $table->string('kind');
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('gross_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
