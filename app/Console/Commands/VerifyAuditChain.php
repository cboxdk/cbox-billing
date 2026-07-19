<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Audit\AuditChainVerifier;
use Illuminate\Console\Command;

/**
 * Walk the operator audit hash chain and report whether it is intact. Exit code 0 when every
 * row verifies, 1 when a break is found (so CI / a monitor can gate on it). This is the
 * tamper-EVIDENCE surface: it detects an in-place edit, a re-linked row, or a sequence gap —
 * it does not, and cannot, prove the trail was never rewritten wholesale (the chain is unkeyed).
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the tamper-evident operator audit hash chain and report any break.';

    public function handle(AuditChainVerifier $verifier): int
    {
        $status = $verifier->verify();

        if ($status->intact) {
            $this->info($status->summary());

            return self::SUCCESS;
        }

        $this->error($status->summary());

        return self::FAILURE;
    }
}
