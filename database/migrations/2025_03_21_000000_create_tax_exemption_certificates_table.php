<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax exemption certificates — the captured, verified proof that an organization is exempt
 * from tax in a jurisdiction (US resale / nonprofit / government, or a generic country
 * exemption). A verified, non-expired certificate whose `jurisdiction` covers the place of
 * supply zero-rates the tax for that jurisdiction only (deny-by-default: everything else is
 * still taxed).
 *
 * `document_path` points at a file on the PRIVATE disk (never web-served without an authz
 * check). `jurisdiction` is either an ISO 3166-2 subdivision (e.g. `US-CA`), a country code
 * (`US` federal, `DK`), matched against the transaction's resolved jurisdiction.
 *
 * The invoice/line columns added here are the audit trail: an exempt invoice records which
 * certificate zero-rated it (`exemption_certificate_id` + `exemption_reason`), and each line
 * records the engine's tax verdict (`tax_treatment` / `tax_note` / `tax_rate`) so the
 * exemption is legible on the document itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_exemption_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->string('jurisdiction', 8);
            $table->string('exemption_type', 16);
            $table->string('certificate_number', 64);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->string('document_mime', 100)->nullable();
            $table->unsignedInteger('document_size')->nullable();
            $table->string('verified_by_sub')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'expires_at']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // The exemption audit trail on the invoice: which certificate zero-rated it, and a
        // human-readable reason (cert type + number + jurisdiction).
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('exemption_certificate_id')->nullable()->after('gateway_reference');
            $table->string('exemption_reason')->nullable()->after('exemption_certificate_id');

            $table->foreign('exemption_certificate_id')->references('id')->on('tax_exemption_certificates')->nullOnDelete();
        });

        // The per-line tax verdict from the engine, persisted so an exempt line is legible on
        // the invoice (treatment = `exempt`, note = the certificate reason).
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('tax_treatment', 24)->nullable()->after('amount_minor');
            $table->string('tax_note')->nullable()->after('tax_treatment');
            $table->string('tax_rate', 12)->nullable()->after('tax_note');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['tax_treatment', 'tax_note', 'tax_rate']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['exemption_certificate_id']);
            $table->dropColumn(['exemption_certificate_id', 'exemption_reason']);
        });

        Schema::dropIfExists('tax_exemption_certificates');
    }
};
