<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Cpq\Contracts\CapturesSignature;
use App\Billing\Cpq\Contracts\ProvisionsFromQuote;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Cpq\Exceptions\SignatureRejected;
use App\Billing\Cpq\ValueObjects\SignatureRequest;
use App\Models\Quote;
use App\Models\QuoteAcceptance;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Drives customer acceptance / decline of a sent quote from the hosted order form. Acceptance is an
 * e-signature-by-acceptance captured through the {@see CapturesSignature} seam (the null provider
 * verifies typed name + explicit agreement), written to an IMMUTABLE {@see QuoteAcceptance} record
 * with the timestamp/IP, and then provisions the subscription through {@see ProvisionsFromQuote} —
 * all in one transaction. IDEMPOTENT: a quote already accepted returns its existing acceptance
 * without recording a second one or provisioning a second subscription. Both acceptance and decline
 * are audit-logged (the order-form page runs outside the console audit middleware).
 */
readonly class QuoteAcceptanceService
{
    public function __construct(
        private ConnectionInterface $db,
        private CapturesSignature $signatures,
        private ProvisionsFromQuote $provisioner,
        private QuoteCalculator $calculator,
        private RecordsAudit $audit,
    ) {}

    /**
     * Accept a sent, unexpired quote. Returns the acceptance record (existing one on a re-accept).
     *
     * @throws QuoteActionDenied|SignatureRejected
     */
    public function accept(Quote $quote, SignatureRequest $request): QuoteAcceptance
    {
        // Idempotency: an already-accepted quote returns its acceptance, provisions nothing new.
        if ($quote->status === QuoteStatus::Accepted) {
            $existing = $quote->acceptance;

            if ($existing instanceof QuoteAcceptance) {
                return $existing;
            }
        }

        if (! $quote->isOpenToCustomer()) {
            throw QuoteActionDenied::notOpen();
        }

        // Capture the signature (deny-by-default: missing name / unchecked agreement is rejected).
        $signature = $this->signatures->capture($quote, $request);

        // Price the quote for the acceptance snapshot (what was accepted).
        $computation = $this->calculator->compute($quote);

        $acceptance = $this->db->transaction(function () use ($quote, $request, $signature, $computation): QuoteAcceptance {
            $acceptance = $quote->acceptance()->create([
                'signer_name' => trim($request->signerName),
                'signer_email' => $request->signerEmail,
                'agreed' => $request->agreed,
                'signature_provider' => $signature->provider,
                'signature_reference' => $signature->reference,
                'ip' => $request->ip,
                'user_agent' => $request->userAgent,
                'currency' => $quote->currency,
                'accepted_total_minor' => $computation->firstInvoiceGross->minor(),
                'committed_value_minor' => $computation->committedNet->minor(),
                'accepted_at' => Carbon::now(),
            ]);

            $quote->forceFill([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => Carbon::now(),
            ])->save();

            // Provision the subscription (idempotent) inside the same transaction.
            $this->provisioner->provision($quote->refresh());

            return $acceptance;
        });

        $this->audit->record(
            AuditAction::QuoteAccepted,
            AuditTarget::model($quote, $quote->organization_id),
            sprintf('Quote %s accepted by %s.', $quote->number, $acceptance->signer_name),
            [
                'signer' => $acceptance->signer_name,
                'signature_provider' => $signature->provider,
                'ip' => $request->ip,
            ],
        );

        return $acceptance;
    }

    /** Decline a sent quote with an optional reason. Terminal. */
    public function decline(Quote $quote, ?string $reason): Quote
    {
        if (! $quote->isOpenToCustomer()) {
            throw QuoteActionDenied::notOpen();
        }

        $quote->forceFill([
            'status' => QuoteStatus::Declined,
            'declined_at' => Carbon::now(),
            'decline_reason' => $reason,
        ])->save();

        $this->audit->record(
            AuditAction::QuoteDeclined,
            AuditTarget::model($quote, $quote->organization_id),
            sprintf('Quote %s declined.', $quote->number),
            $reason !== null && $reason !== '' ? ['reason' => $reason] : [],
        );

        return $quote;
    }
}
