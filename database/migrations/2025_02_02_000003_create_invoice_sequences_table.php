<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-selling-entity legal invoice numbering. Numbering is monotonic and gapless and
 * MUST NOT be shared across entities, so each seller has its own counter row. The next
 * value is drawn under a row lock inside the same transaction that finalizes the
 * invoice, so a concurrent finalize never reuses a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->string('seller')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
