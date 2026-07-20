<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SubscriptionCancellation;
use App\Models\SubscriptionMrrMovement;
use App\Models\WalletAdjustment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * A per-customer event timeline for the customer-detail page. It aggregates the real records
 * an org accrues — invoices, credit notes, subscription MRR movements, cancellations, wallet
 * adjustments, and the Cbox ID provisioning webhook deliveries kept in the access mirror —
 * into one time-sorted feed with cross-links, so an operator sees the account's history in
 * one place without stitching it together by hand. Read-only: it never writes.
 */
readonly class CustomerAuditLog
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * The newest-first event feed for one organization.
     *
     * @return list<array{at: string, sort: int, type: string, label: string, detail: ?string, href: ?string}>
     */
    public function forOrganization(string $organizationId, int $limit = 60): array
    {
        $events = [];

        foreach (Invoice::query()->where('organization_id', $organizationId)->get() as $invoice) {
            $at = $invoice->issued_at ?? $invoice->created_at;
            $events[] = $this->event($at, 'invoice', sprintf('Invoice %s — %s', $invoice->number, $invoice->status->value), null, route('billing.invoices.show', $invoice->id));
        }

        foreach (CreditNote::query()->where('organization_id', $organizationId)->get() as $note) {
            $events[] = $this->event($note->issued_at ?? $note->created_at, 'credit-note', sprintf('Credit note %s (%s)', $note->number, $note->kind), $note->reason, route('billing.credit-notes.show', $note->id));
        }

        foreach (SubscriptionMrrMovement::query()->where('organization_id', $organizationId)->get() as $movement) {
            $href = $movement->subscription_id !== null ? route('billing.subscriptions.show', $movement->subscription_id) : null;
            $events[] = $this->event($movement->occurred_at ?? $movement->created_at, 'subscription', sprintf('MRR %s', $movement->kind), $this->mrrDelta($movement), $href);
        }

        foreach (SubscriptionCancellation::query()->where('organization_id', $organizationId)->get() as $cancellation) {
            $href = $cancellation->subscription_id !== null ? route('billing.subscriptions.show', $cancellation->subscription_id) : null;
            $events[] = $this->event($cancellation->created_at, 'subscription', sprintf('Subscription %s cancellation', $cancellation->mode), $cancellation->reason, $href);
        }

        foreach (WalletAdjustment::query()->where('organization_id', $organizationId)->get() as $adjustment) {
            $events[] = $this->event($adjustment->created_at, 'wallet', sprintf('Wallet %s %d %s', $adjustment->direction, abs((int) $adjustment->amount), $adjustment->denomination_code), $adjustment->reason, null);
        }

        foreach ($this->db->table('cbox_id_webhook_deliveries')->where('organization_id', $organizationId)->get() as $delivery) {
            $eventType = is_scalar($delivery->event_type) ? (string) $delivery->event_type : 'event';
            $events[] = $this->event($this->ts($delivery->processed_at), 'provisioning', sprintf('Provisioning: %s', $eventType), null, null);
        }

        usort($events, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        return array_slice($events, 0, $limit);
    }

    /**
     * @return array{at: string, sort: int, type: string, label: string, detail: ?string, href: ?string}
     */
    private function event(mixed $at, string $type, string $label, ?string $detail, ?string $href): array
    {
        $carbon = $this->ts($at);

        return [
            'at' => $carbon?->format('Y-m-d H:i') ?? '—',
            'sort' => $carbon?->getTimestamp() ?? 0,
            'type' => $type,
            'label' => $label,
            'detail' => $detail !== null && $detail !== '' ? $detail : null,
            'href' => $href,
        ];
    }

    private function mrrDelta(SubscriptionMrrMovement $movement): string
    {
        return sprintf('%s → %s %s', number_format((int) $movement->previous_mrr_minor / 100, 2), number_format((int) $movement->new_mrr_minor / 100, 2), $movement->currency);
    }

    private function ts(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
