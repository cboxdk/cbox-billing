<?php

declare(strict_types=1);

use App\Models\ApprovalRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general approval-workflow engine (maker-checker / two-person rule). A sensitive operator
 * mutation that trips the policy is captured as a held {@see ApprovalRequest} —
 * carrying the typed action, its serialized payload, the maker, and the money at stake — and
 * does NOT take effect until a distinct checker (or M distinct checkers) approves it, at which
 * point the held action runs exactly once. `approval_decisions` records each checker's
 * approve/reject, one row per checker, which is how the two-person rule and an M-of-N quorum
 * are enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();

            // The typed action + the JSON-safe params needed to reconstruct and run it later.
            $table->string('action_type', 64);
            $table->json('payload');

            // The maker (who requested) and their stated reason.
            $table->string('requested_by_sub');
            $table->string('requested_by_name', 200)->nullable();
            $table->string('reason', 500)->nullable();

            // Lifecycle: pending · approved · rejected · executed · expired · canceled.
            $table->string('status', 20)->default('pending');

            // Threshold/display facts, captured so the queue renders without rebuilding.
            $table->string('organization_id')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id')->nullable();

            // The quorum captured at creation (the M in M-of-N), so a later config change does
            // not retroactively alter an in-flight request.
            $table->unsignedInteger('required_approvals')->default(1);

            // The decision stamp (the FINAL/quorum-reaching checker) + the execution proof.
            $table->string('approved_by_sub')->nullable();
            $table->string('approved_by_name', 200)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->boolean('livemode')->default(true);
            $table->timestamps();

            $table->index(['status', 'livemode']);
            $table->index('action_type');
            $table->index('organization_id');
            $table->index('requested_by_sub');
        });

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->string('approver_sub');
            $table->string('approver_name', 200)->nullable();
            $table->string('decision', 12); // approve · reject
            $table->string('note', 500)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            // One decision per checker per request — the backstop for the two-person rule and
            // the distinct-approver quorum count.
            $table->unique(['approval_request_id', 'approver_sub']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};
