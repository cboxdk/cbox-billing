<?php

declare(strict_types=1);

namespace App\Billing\Import\Adapters;

use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\Normalized\NormalizedCoupon;
use App\Billing\Import\Normalized\NormalizedCustomer;
use App\Billing\Import\Normalized\NormalizedDataset;
use App\Billing\Import\Normalized\NormalizedInterval;
use App\Billing\Import\Normalized\NormalizedInvoice;
use App\Billing\Import\Normalized\NormalizedInvoiceLine;
use App\Billing\Import\Normalized\NormalizedPlan;
use App\Billing\Import\Normalized\NormalizedPrice;
use App\Billing\Import\Normalized\NormalizedProduct;
use App\Billing\Import\Normalized\NormalizedSubscription;

/**
 * Maps a Stripe data export into the normalized model.
 *
 * Stripe quirks handled here:
 *  - Amounts are INTEGER MINOR units (`unit_amount`, `amount_off`, `subtotal`, `tax`, `total`) —
 *    passed straight through.
 *  - Timestamps are UNIX EPOCH seconds (`created`, `current_period_start`, `trial_end`).
 *  - A Stripe `price` object IS the billable unit (it carries the amount, currency and
 *    `recurring.interval`) and groups under a `product`. Each price therefore becomes one
 *    normalized plan (keyed by the price id) plus one normalized price row.
 *  - A subscription references its price at `items.data[0].price.id`; its discount coupon at
 *    `discount.coupon.id`.
 *  - Coupon `duration` is `once` / `repeating` / `forever`, with `duration_in_months` for the
 *    repeating case; the coupon `id` is used as the redeem code.
 */
readonly class StripeAdapter extends AbstractSourceAdapter
{
    public function source(): ImportSource
    {
        return ImportSource::Stripe;
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function expectedFiles(): array
    {
        return [
            'products' => 'Products (id, name, description).',
            'prices' => 'Prices (id, product, unit_amount in minor units, currency, recurring.interval).',
            'coupons' => 'Coupons (id, percent_off / amount_off, duration, duration_in_months).',
            'customers' => 'Customers (id, name, email, currency, address.country).',
            'subscriptions' => 'Subscriptions (id, customer, items.data[].price, status, current_period_*).',
            'invoices' => 'Invoices (id, customer, number, subtotal, tax, total, status, lines.data[]).',
        ];
    }

    public function parse(SourceExport $export): NormalizedDataset
    {
        return new NormalizedDataset(
            products: array_map($this->product(...), $export->records('products')),
            plans: array_map($this->plan(...), $export->records('prices')),
            prices: array_map($this->price(...), $export->records('prices')),
            coupons: array_map($this->coupon(...), $export->records('coupons')),
            customers: array_map($this->customer(...), $export->records('customers')),
            subscriptions: array_map($this->subscription(...), $export->records('subscriptions')),
            invoices: array_map($this->invoice(...), $export->records('invoices')),
        );
    }

    /** @param array<string, mixed> $r */
    private function product(array $r): NormalizedProduct
    {
        $id = (string) ($this->string($r, 'id') ?? '');

        return new NormalizedProduct(
            sourceId: $id,
            key: (string) ($this->string($r, 'key', 'id') ?? $id),
            name: (string) ($this->string($r, 'name') ?? $id),
            description: $this->string($r, 'description'),
            createdAt: $this->timestamp($r, 'created'),
        );
    }

    /** @param array<string, mixed> $r */
    private function plan(array $r): NormalizedPlan
    {
        $id = (string) ($this->string($r, 'id') ?? '');
        $raw = (string) ($this->string($r, 'recurring.interval', 'interval') ?? '');

        return new NormalizedPlan(
            sourceId: $id,
            productSourceId: $this->string($r, 'product'),
            key: (string) ($this->string($r, 'nickname', 'id') ?? $id),
            name: (string) ($this->string($r, 'nickname', 'id') ?? $id),
            interval: NormalizedInterval::fromProvider($raw),
            rawInterval: $raw,
            createdAt: $this->timestamp($r, 'created'),
        );
    }

    /** @param array<string, mixed> $r */
    private function price(array $r): NormalizedPrice
    {
        $id = (string) ($this->string($r, 'id') ?? '');

        return new NormalizedPrice(
            sourceId: $id,
            planSourceId: $id,
            currency: $this->currency($this->string($r, 'currency')),
            amountMinor: $this->minorFromMinor($r, 'unit_amount', 'amount') ?? 0,
            createdAt: $this->timestamp($r, 'created'),
        );
    }

    /** @param array<string, mixed> $r */
    private function coupon(array $r): NormalizedCoupon
    {
        $percent = $this->int($r, 'percent_off');

        return new NormalizedCoupon(
            sourceId: (string) ($this->string($r, 'id') ?? ''),
            code: (string) ($this->string($r, 'id') ?? ''),
            name: $this->string($r, 'name'),
            kind: $percent !== null ? 'percent' : 'fixed',
            percentOff: $percent,
            amountOffMinor: $this->minorFromMinor($r, 'amount_off'),
            currency: $this->currency($this->string($r, 'currency')),
            duration: strtolower((string) ($this->string($r, 'duration') ?? 'once')),
            durationInPeriods: $this->int($r, 'duration_in_months'),
            maxRedemptions: $this->int($r, 'max_redemptions'),
            redeemBy: $this->timestamp($r, 'redeem_by'),
            createdAt: $this->timestamp($r, 'created'),
        );
    }

    /** @param array<string, mixed> $r */
    private function customer(array $r): NormalizedCustomer
    {
        $id = (string) ($this->string($r, 'id') ?? '');

        return new NormalizedCustomer(
            sourceId: $id,
            name: (string) ($this->string($r, 'name', 'description', 'email') ?? $id),
            email: $this->string($r, 'email'),
            currency: $this->currency($this->string($r, 'currency')),
            country: $this->string($r, 'address.country'),
            taxId: $this->string($r, 'tax_id'),
            createdAt: $this->timestamp($r, 'created'),
        );
    }

    /** @param array<string, mixed> $r */
    private function subscription(array $r): NormalizedSubscription
    {
        $item = $this->firstItem($r);

        return new NormalizedSubscription(
            sourceId: (string) ($this->string($r, 'id') ?? ''),
            customerSourceId: (string) ($this->string($r, 'customer') ?? ''),
            planSourceId: (string) ($this->itemPrice($item) ?? ''),
            status: $this->status((string) ($this->string($r, 'status') ?? 'active')),
            seats: $this->int($r, 'quantity') ?? $this->itemQuantity($item) ?? 1,
            currency: $this->currency($this->string($r, 'currency')),
            currentPeriodStart: $this->timestamp($r, 'current_period_start'),
            currentPeriodEnd: $this->timestamp($r, 'current_period_end'),
            trialEndsAt: $this->timestamp($r, 'trial_end'),
            canceledAt: $this->timestamp($r, 'canceled_at'),
            createdAt: $this->timestamp($r, 'created', 'start_date'),
            couponCode: $this->string($r, 'discount.coupon.id'),
        );
    }

    /** @param array<string, mixed> $r */
    private function invoice(array $r): NormalizedInvoice
    {
        return new NormalizedInvoice(
            sourceId: (string) ($this->string($r, 'id') ?? ''),
            customerSourceId: (string) ($this->string($r, 'customer') ?? ''),
            subscriptionSourceId: $this->string($r, 'subscription'),
            number: (string) ($this->string($r, 'number', 'id') ?? ''),
            currency: $this->currency($this->string($r, 'currency')),
            subtotalMinor: $this->minorFromMinor($r, 'subtotal') ?? 0,
            taxMinor: $this->minorFromMinor($r, 'tax') ?? 0,
            totalMinor: $this->minorFromMinor($r, 'total') ?? 0,
            status: strtolower((string) ($this->string($r, 'status') ?? 'open')),
            issuedAt: $this->timestamp($r, 'status_transitions.finalized_at', 'created'),
            periodStart: $this->timestamp($r, 'period_start'),
            periodEnd: $this->timestamp($r, 'period_end'),
            lines: $this->invoiceLines($r),
        );
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<NormalizedInvoiceLine>
     */
    private function invoiceLines(array $r): array
    {
        $data = $this->dig($r, 'lines.data');
        $lines = [];

        if (is_array($data)) {
            foreach ($data as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $line = $this->asRecord($raw);

                $lines[] = new NormalizedInvoiceLine(
                    description: (string) ($this->string($line, 'description') ?? 'Line item'),
                    quantity: $this->int($line, 'quantity') ?? 1,
                    unitAmountMinor: $this->minorFromMinor($line, 'price.unit_amount', 'unit_amount') ?? 0,
                    amountMinor: $this->minorFromMinor($line, 'amount') ?? 0,
                );
            }
        }

        return $lines;
    }

    /**
     * The first subscription item (`items.data[0]`), or an empty record.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function firstItem(array $r): array
    {
        $data = $this->dig($r, 'items.data');

        if (is_array($data) && isset($data[0])) {
            return $this->asRecord($data[0]);
        }

        return [];
    }

    /** @param array<string, mixed> $item */
    private function itemPrice(array $item): ?string
    {
        return $this->string($item, 'price.id', 'plan.id', 'price');
    }

    /** @param array<string, mixed> $item */
    private function itemQuantity(array $item): ?int
    {
        return $this->int($item, 'quantity');
    }

    /** Map a Stripe subscription status onto the app's status vocabulary. */
    private function status(string $status): string
    {
        return match (strtolower($status)) {
            'trialing' => 'trialing',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            'paused' => 'paused',
            default => 'active',
        };
    }
}
