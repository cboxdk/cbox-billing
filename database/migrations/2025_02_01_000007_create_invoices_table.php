<?php

declare(strict_types=1);

use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A legal invoice issued to an organization by a seller of record. Totals are carried
 * in integer minor units of `currency`. `number` is the seller's sequence-assigned
 * document number and is unique per seller. `status` moves draft → open → paid (or
 * void); `paid_at` and `gateway_reference` are stamped when a settled payment is
 * applied by the {@see InvoicePaymentApplier} seam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->string('seller');
            $table->string('number');
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('status')->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['seller', 'number']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
