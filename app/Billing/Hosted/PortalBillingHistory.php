<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Support\MoneyFormatter;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CreditNote;
use App\Models\Invoice;
use Carbon\CarbonInterface;

/**
 * The customer-facing billing-history timeline for ONE organization — broader than the bare
 * invoices table: it folds the org's invoices (issued / paid / void), the receipt each paid
 * invoice produced, its credit notes (refunds / adjustments), and its coupon redemptions
 * into one newest-first feed. Every row is scoped to the passed organization id, so a portal
 * token for org A can never surface org B's history.
 *
 * Read-only. Amounts are always formatted through the single {@see MoneyFormatter} money seam
 * from the stored integer-minor value — never a re-derived float — so JPY/ISK and the like
 * present correctly. Invoice and receipt rows carry the `invoice_id` the portal turns into a
 * token-scoped PDF link; credit notes and coupon redemptions have no customer document, so
 * they present without one rather than linking to a console-only page.
 */
readonly class PortalBillingHistory
{
    /**
     * @return list<array{
     *     at: string, sort: int, type: string, title: string, detail: ?string,
     *     amount: ?string, status: string, tone: string, invoice_id: ?int
     * }>
     */
    public function forOrganization(string $organizationId, int $limit = 60): array
    {
        $events = [];

        foreach (Invoice::query()->where('organization_id', $organizationId)->get() as $invoice) {
            $issuedAt = $invoice->issued_at ?? $invoice->created_at;

            $events[] = $this->row(
                at: $issuedAt,
                type: 'invoice',
                title: 'Invoice '.$invoice->number,
                detail: $this->periodLabel($invoice),
                amount: MoneyFormatter::minor($invoice->total_minor, $invoice->currency),
                status: $invoice->status,
                tone: $this->invoiceTone($invoice->status),
                invoiceId: $invoice->id,
            );

            // A paid invoice also produced a receipt/payment — a distinct history event at the
            // settlement instant, cross-linking back to the same downloadable invoice.
            if ($invoice->status === 'paid' && $invoice->paid_at !== null) {
                $events[] = $this->row(
                    at: $invoice->paid_at,
                    type: 'payment',
                    title: 'Payment received',
                    detail: $invoice->gateway_reference !== null && $invoice->gateway_reference !== ''
                        ? 'Ref '.$invoice->gateway_reference
                        : 'Invoice '.$invoice->number,
                    amount: MoneyFormatter::minor($invoice->total_minor, $invoice->currency),
                    status: 'paid',
                    tone: 'success',
                    invoiceId: $invoice->id,
                );
            }
        }

        foreach (CreditNote::query()->where('organization_id', $organizationId)->get() as $note) {
            $isRefund = $note->kind === 'refund';

            $events[] = $this->row(
                at: $note->issued_at,
                type: 'credit_note',
                title: ($isRefund ? 'Refund' : 'Credit note').' '.$note->number,
                detail: $note->reason !== '' ? $note->reason : 'Against invoice '.$note->invoice_number,
                // A credit note returns money to the customer: shown as a negative magnitude.
                amount: '−'.MoneyFormatter::minor($note->gross_minor, $note->currency),
                status: $isRefund ? 'refunded' : 'credited',
                tone: 'info',
                invoiceId: null,
            );
        }

        foreach (CouponRedemption::query()->with('coupon')->where('organization_id', $organizationId)->get() as $redemption) {
            $coupon = $redemption->coupon;
            $code = $coupon instanceof Coupon ? $coupon->code : 'promo';

            $events[] = $this->row(
                at: $redemption->redeemed_at,
                type: 'coupon',
                title: 'Promo code '.$code.' applied',
                detail: null,
                amount: null,
                status: 'redeemed',
                tone: 'muted',
                invoiceId: null,
            );
        }

        usort($events, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        return array_slice($events, 0, $limit);
    }

    /**
     * @return array{
     *     at: string, sort: int, type: string, title: string, detail: ?string,
     *     amount: ?string, status: string, tone: string, invoice_id: ?int
     * }
     */
    private function row(?CarbonInterface $at, string $type, string $title, ?string $detail, ?string $amount, string $status, string $tone, ?int $invoiceId): array
    {
        return [
            'at' => $at?->format('Y-m-d') ?? '—',
            'sort' => $at?->getTimestamp() ?? 0,
            'type' => $type,
            'title' => $title,
            'detail' => $detail !== null && $detail !== '' ? $detail : null,
            'amount' => $amount,
            'status' => $status,
            'tone' => $tone,
            'invoice_id' => $invoiceId,
        ];
    }

    private function periodLabel(Invoice $invoice): ?string
    {
        if ($invoice->period_start === null || $invoice->period_end === null) {
            return null;
        }

        return $invoice->period_start->format('Y-m-d').' – '.$invoice->period_end->format('Y-m-d');
    }

    private function invoiceTone(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'open' => 'warning',
            'void' => 'muted',
            default => 'muted',
        };
    }
}
