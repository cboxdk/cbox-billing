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
 * Maps a Chargebee export into the normalized model.
 *
 * Chargebee quirks handled here (its field names differ from Stripe's):
 *  - Amounts are INTEGER MINOR units too (`price`, `discount_amount`, `sub_total`, `total`).
 *  - Timestamps are UNIX EPOCH seconds (`created_at`, `current_term_start`, `trial_end`).
 *  - Customers split the name into `first_name` / `last_name` (or `company`); the currency is
 *    `preferred_currency_code` / `currency_code`; the country is `billing_address.country`.
 *  - A plan carries its own `price` + `period_unit` (month/year/week/day) and groups under an
 *    `item_family` (mapped to a product via `item_family_id`).
 *  - Subscription status vocabulary is `active` / `in_trial` / `non_renewing` / `cancelled` /
 *    `paused`; the period is `current_term_start` / `current_term_end`; the coupon is `coupon_id`.
 *  - Coupon `discount_type` is `percentage` / `fixed_amount`; `duration_type` is `one_time` /
 *    `limited_period` / `forever`.
 */
readonly class ChargebeeAdapter extends AbstractSourceAdapter
{
    public function source(): ImportSource
    {
        return ImportSource::Chargebee;
    }

    public function label(): string
    {
        return 'Chargebee';
    }

    public function expectedFiles(): array
    {
        return [
            'item_families' => 'Item families / products (id, name, description).',
            'plans' => 'Plans (id, name, price in minor units, period_unit, currency_code, item_family_id).',
            'coupons' => 'Coupons (id, discount_type, discount_percentage / discount_amount, duration_type).',
            'customers' => 'Customers (id, first_name, last_name, email, preferred_currency_code).',
            'subscriptions' => 'Subscriptions (id, customer_id, plan_id, plan_quantity, status, current_term_*).',
            'invoices' => 'Invoices (id, customer_id, sub_total, tax, total, status, line_items[]).',
        ];
    }

    public function parse(SourceExport $export): NormalizedDataset
    {
        $plans = $this->pick($export, 'plans', 'items', 'item_prices');

        return new NormalizedDataset(
            products: array_map($this->product(...), $this->pick($export, 'item_families', 'products')),
            plans: array_map($this->plan(...), $plans),
            prices: array_map($this->price(...), $plans),
            coupons: array_map($this->coupon(...), $export->records('coupons')),
            customers: array_map($this->customer(...), $export->records('customers')),
            subscriptions: array_map($this->subscription(...), $export->records('subscriptions')),
            invoices: array_map($this->invoice(...), $export->records('invoices')),
        );
    }

    /**
     * The first non-empty of several possible resource names (Chargebee has evolved its resource
     * naming: plans → items/item_prices, products → item_families).
     *
     * @return list<array<string, mixed>>
     */
    private function pick(SourceExport $export, string ...$resources): array
    {
        foreach ($resources as $resource) {
            if ($export->has($resource)) {
                return $export->records($resource);
            }
        }

        return [];
    }

    /** @param array<string, mixed> $r */
    private function product(array $r): NormalizedProduct
    {
        $id = (string) ($this->string($r, 'id') ?? '');

        return new NormalizedProduct(
            sourceId: $id,
            key: (string) ($this->string($r, 'id') ?? $id),
            name: (string) ($this->string($r, 'name') ?? $id),
            description: $this->string($r, 'description'),
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function plan(array $r): NormalizedPlan
    {
        $id = (string) ($this->string($r, 'id') ?? '');
        $raw = (string) ($this->string($r, 'period_unit', 'period_type') ?? '');

        return new NormalizedPlan(
            sourceId: $id,
            productSourceId: $this->string($r, 'item_family_id', 'product_id'),
            key: $id,
            name: (string) ($this->string($r, 'name', 'external_name', 'id') ?? $id),
            interval: NormalizedInterval::fromProvider($raw),
            rawInterval: $raw,
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function price(array $r): NormalizedPrice
    {
        $id = (string) ($this->string($r, 'id') ?? '');

        return new NormalizedPrice(
            sourceId: $id,
            planSourceId: $id,
            currency: $this->currency($this->string($r, 'currency_code')),
            amountMinor: $this->minorFromMinor($r, 'price', 'amount') ?? 0,
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function coupon(array $r): NormalizedCoupon
    {
        $type = strtolower((string) ($this->string($r, 'discount_type') ?? ''));
        $isPercent = $type === 'percentage' || $type === 'percent';

        return new NormalizedCoupon(
            sourceId: (string) ($this->string($r, 'id') ?? ''),
            code: (string) ($this->string($r, 'id') ?? ''),
            name: $this->string($r, 'name'),
            kind: $isPercent ? 'percent' : 'fixed',
            percentOff: $isPercent ? $this->int($r, 'discount_percentage', 'discount_percent') : null,
            amountOffMinor: $isPercent ? null : $this->minorFromMinor($r, 'discount_amount'),
            currency: $this->currency($this->string($r, 'currency_code')),
            duration: $this->duration((string) ($this->string($r, 'duration_type') ?? 'one_time')),
            durationInPeriods: $this->int($r, 'duration_month', 'period'),
            maxRedemptions: $this->int($r, 'max_redemptions'),
            redeemBy: $this->timestamp($r, 'valid_till'),
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function customer(array $r): NormalizedCustomer
    {
        $id = (string) ($this->string($r, 'id') ?? '');
        $name = trim(implode(' ', array_filter([
            $this->string($r, 'first_name'),
            $this->string($r, 'last_name'),
        ])));

        return new NormalizedCustomer(
            sourceId: $id,
            name: $name !== '' ? $name : (string) ($this->string($r, 'company', 'email') ?? $id),
            email: $this->string($r, 'email'),
            currency: $this->currency($this->string($r, 'preferred_currency_code', 'currency_code')),
            country: $this->string($r, 'billing_address.country', 'country'),
            taxId: $this->string($r, 'vat_number', 'tax_id'),
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function subscription(array $r): NormalizedSubscription
    {
        return new NormalizedSubscription(
            sourceId: (string) ($this->string($r, 'id') ?? ''),
            customerSourceId: (string) ($this->string($r, 'customer_id') ?? ''),
            planSourceId: (string) ($this->string($r, 'plan_id', 'subscription_items.0.item_price_id') ?? ''),
            status: $this->status((string) ($this->string($r, 'status') ?? 'active')),
            seats: $this->int($r, 'plan_quantity', 'quantity') ?? 1,
            currency: $this->currency($this->string($r, 'currency_code')),
            currentPeriodStart: $this->timestamp($r, 'current_term_start'),
            currentPeriodEnd: $this->timestamp($r, 'current_term_end'),
            trialEndsAt: $this->timestamp($r, 'trial_end'),
            canceledAt: $this->timestamp($r, 'cancelled_at', 'canceled_at'),
            createdAt: $this->timestamp($r, 'created_at', 'started_at'),
            couponCode: $this->string($r, 'coupon_id', 'coupon'),
        );
    }

    /** @param array<string, mixed> $r */
    private function invoice(array $r): NormalizedInvoice
    {
        return new NormalizedInvoice(
            sourceId: (string) ($this->string($r, 'id') ?? ''),
            customerSourceId: (string) ($this->string($r, 'customer_id') ?? ''),
            subscriptionSourceId: $this->string($r, 'subscription_id'),
            number: (string) ($this->string($r, 'id') ?? ''),
            currency: $this->currency($this->string($r, 'currency_code')),
            subtotalMinor: $this->minorFromMinor($r, 'sub_total', 'subtotal') ?? 0,
            taxMinor: $this->minorFromMinor($r, 'tax', 'tax_amount') ?? 0,
            totalMinor: $this->minorFromMinor($r, 'total') ?? 0,
            status: $this->invoiceStatus((string) ($this->string($r, 'status') ?? 'payment_due')),
            issuedAt: $this->timestamp($r, 'date', 'generated_at'),
            periodStart: $this->timestamp($r, 'line_items.0.date_from'),
            periodEnd: $this->timestamp($r, 'line_items.0.date_to'),
            lines: $this->invoiceLines($r),
        );
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<NormalizedInvoiceLine>
     */
    private function invoiceLines(array $r): array
    {
        $data = $this->dig($r, 'line_items');
        $lines = [];

        if (is_array($data)) {
            foreach ($data as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $line = $this->asRecord($raw);

                $lines[] = new NormalizedInvoiceLine(
                    description: (string) ($this->string($line, 'description', 'entity_id') ?? 'Line item'),
                    quantity: $this->int($line, 'quantity') ?? 1,
                    unitAmountMinor: $this->minorFromMinor($line, 'unit_amount') ?? 0,
                    amountMinor: $this->minorFromMinor($line, 'amount') ?? 0,
                );
            }
        }

        return $lines;
    }

    private function status(string $status): string
    {
        return match (strtolower($status)) {
            'in_trial' => 'trialing',
            'cancelled', 'canceled' => 'canceled',
            'paused' => 'paused',
            default => 'active',
        };
    }

    private function invoiceStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid' => 'paid',
            'voided' => 'void',
            'not_paid', 'payment_due', 'posted' => 'open',
            default => strtolower($status),
        };
    }

    private function duration(string $type): string
    {
        return match (strtolower($type)) {
            'one_time' => 'once',
            'limited_period' => 'repeating',
            'forever' => 'forever',
            default => strtolower($type),
        };
    }
}
