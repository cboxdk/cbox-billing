<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Auth\CurrentUser;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Cpq\ValueObjects\QuoteComputation;
use App\Billing\Support\MoneyFormatter;
use App\Models\Quote;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * The deal-desk approval router. On SUBMIT it evaluates the configured threshold
 * (`billing.quotes.approval`) against the priced quote: at or above the amount floor (first-invoice
 * gross), OR at or above the discount ceiling (the largest line discount), routes the quote to
 * `pending_approval`; otherwise the quote is auto-approved. An approver holding `quotes:approve`
 * then approves (→ `approved`, ready to send) or rejects (kicks it back to `draft` with a reason).
 * Approvals and rejections are audit-logged with the operator identity.
 */
readonly class QuoteApprovalRouter
{
    public function __construct(
        private Config $config,
        private RecordsAudit $audit,
        private CurrentUser $current,
    ) {}

    /**
     * Whether the priced quote exceeds the deal-desk threshold. Either gate trips it; both unset
     * (amount null and discount 0) means nothing needs approval.
     */
    public function requiresApproval(QuoteComputation $computation): bool
    {
        $amountFloor = $this->amountFloor();
        $discountCeiling = $this->discountCeiling();

        $overAmount = $amountFloor !== null && $computation->firstInvoiceGross->minor() >= $amountFloor;
        $overDiscount = $discountCeiling > 0 && $computation->largestDiscountPercent() >= $discountCeiling;

        return $overAmount || $overDiscount;
    }

    /**
     * Submit a draft for review. Above threshold → `pending_approval`; below → auto-approved
     * (`approved`). Requires at least one line. Records the threshold decision on the quote.
     */
    public function submit(Quote $quote, QuoteComputation $computation): Quote
    {
        if (! $quote->isDraft()) {
            throw QuoteActionDenied::notEditable();
        }

        if ($quote->lines()->count() === 0) {
            throw QuoteActionDenied::needsLine();
        }

        $requires = $this->requiresApproval($computation);

        $quote->forceFill([
            'approval_required' => $requires,
            'status' => $requires ? QuoteStatus::PendingApproval : QuoteStatus::Approved,
            // A fresh submission clears any prior rejection.
            'rejected_by_sub' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'approved_by_sub' => $requires ? null : 'system',
            'approved_by_name' => $requires ? null : 'Auto-approved (below threshold)',
            'approved_at' => $requires ? null : Carbon::now(),
        ])->save();

        return $quote;
    }

    /** Approve a quote pending approval, stamping the approver. */
    public function approve(Quote $quote): Quote
    {
        if (! $quote->status->isPendingApproval()) {
            throw QuoteActionDenied::notPendingApproval();
        }

        $operator = $this->current->user();

        $quote->forceFill([
            'status' => QuoteStatus::Approved,
            'approved_by_sub' => $operator?->sub,
            'approved_by_name' => $operator?->name,
            'approved_at' => Carbon::now(),
        ])->save();

        $this->audit->record(
            AuditAction::QuoteApproved,
            AuditTarget::model($quote, $quote->organization_id),
            sprintf('Approved quote %s for %s.', $quote->number, $quote->customerName()),
            ['approver' => $operator?->name],
        );

        return $quote;
    }

    /** Reject a quote pending approval, returning it to draft with the reason recorded. */
    public function reject(Quote $quote, string $reason): Quote
    {
        if (! $quote->status->isPendingApproval()) {
            throw QuoteActionDenied::notPendingApproval();
        }

        $operator = $this->current->user();

        $quote->forceFill([
            'status' => QuoteStatus::Draft,
            'rejected_by_sub' => $operator?->sub,
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->record(
            AuditAction::QuoteRejected,
            AuditTarget::model($quote, $quote->organization_id),
            sprintf('Rejected quote %s: %s', $quote->number, $reason),
            ['approver' => $operator?->name, 'reason' => $reason],
        );

        return $quote;
    }

    private function amountFloor(): ?int
    {
        $value = $this->config->get('billing.quotes.approval.amount_minor');

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    private function discountCeiling(): int
    {
        $value = $this->config->get('billing.quotes.approval.discount_percent');

        return is_numeric($value) ? (int) $value : 0;
    }

    /** A human summary of the threshold for the console. */
    public function thresholdSummary(): string
    {
        $amount = $this->amountFloor();
        $discount = $this->discountCeiling();

        $defaultCurrency = $this->config->get('billing.default_currency');
        $currency = is_string($defaultCurrency) && $defaultCurrency !== '' ? $defaultCurrency : 'DKK';

        $parts = [];
        if ($amount !== null) {
            $parts[] = 'first invoice ≥ '.MoneyFormatter::minor($amount, $currency);
        }
        if ($discount > 0) {
            $parts[] = 'discount ≥ '.$discount.'%';
        }

        return $parts === [] ? 'No approval threshold configured.' : implode(' or ', $parts);
    }
}
