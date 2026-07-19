<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-selling-entity legal credit-note numbering — a SEPARATE monotonic, gapless
 * sequence from invoice numbering (a credit note is its own legal document and never
 * draws or reuses an invoice number). Each seller draws its next value under a row lock,
 * mirroring `invoice_sequences`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_sequences', function (Blueprint $table): void {
            $table->string('seller')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_sequences');
    }
};
