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
use App\Billing\Import\Normalized\NormalizedSubscription;

/**
 * Maps a Recurly export into the normalized model.
 *
 * Recurly quirks handled here (the most divergent of the three):
 *  - Plan + invoice amounts are DECIMAL MAJOR-unit strings/numbers (`unit_amount` = "49.00") —
 *    multiplied up to minor units. But coupon fixed amounts are `discount_in_cents` (already
 *    minor) — a genuinely mixed convention within one provider, handled per field.
 *  - Timestamps are ISO-8601 strings (`created_at`, `current_period_started_at`).
 *  - Natural keys are `code` (`plan.code`, `account.code`) rather than opaque ids.
 *  - Customers are "accounts" with `first_name` / `last_name` / `company`; an account carries no
 *    currency (the subscription does), so an imported org's currency is pinned at subscribe time.
 *  - Plans carry no product — a synthetic per-source product is used (handled in the importer).
 *  - Subscription `state` is `active` / `canceled` / `expired` / `paused` / `future`; the coupon
 *    is at `coupon_redemptions[0].coupon.code`.
 */
readonly class RecurlyAdapter extends AbstractSourceAdapter
{
    public function source(): ImportSource
    {
        return ImportSource::Recurly;
    }

    public function label(): string
    {
        return 'Recurly';
    }

    public function expectedFiles(): array
    {
        return [
            'plans' => 'Plans (code, name, interval_unit, interval_length, currencies[].unit_amount as decimal major units).',
            'coupons' => 'Coupons (code, discount_type, discount_percent / discount_in_cents in minor units, duration).',
            'accounts' => 'Accounts / customers (code, email, first_name, last_name, address.country).',
            'subscriptions' => 'Subscriptions (uuid, account.code, plan.code, quantity, state, current_period_*).',
            'invoices' => 'Invoices (number, account.code, subtotal / tax / total as decimal major units, line_items[]).',
        ];
    }

    public function parse(SourceExport $export): NormalizedDataset
    {
        return new NormalizedDataset(
            products: [],
            plans: array_map($this->plan(...), $export->records('plans')),
            prices: array_map($this->price(...), $export->records('plans')),
            coupons: array_map($this->coupon(...), $export->records('coupons')),
            customers: array_map($this->customer(...), $this->pick($export, 'accounts', 'customers')),
            subscriptions: array_map($this->subscription(...), $export->records('subscriptions')),
            invoices: array_map($this->invoice(...), $export->records('invoices')),
        );
    }

    /**
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
    private function plan(array $r): NormalizedPlan
    {
        $code = (string) ($this->string($r, 'code', 'id') ?? '');
        $unit = strtolower((string) ($this->string($r, 'interval_unit', 'plan_interval_unit') ?? ''));
        $length = $this->int($r, 'interval_length', 'plan_interval_length') ?? 1;

        // Recurly cadence is unit + length ("months", 1). Only a length-1 month/year maps to the
        // engine's monthly/yearly; anything else (a 3-month term, a weekly plan) is unsupported
        // and left for the importer to flag rather than silently rebilled.
        $raw = trim($length.' '.$unit);
        $interval = $length === 1 ? NormalizedInterval::fromProvider($unit) : null;

        return new NormalizedPlan(
            sourceId: $code,
            productSourceId: null,
            key: $code,
            name: (string) ($this->string($r, 'name', 'code') ?? $code),
            interval: $interval,
            rawInterval: $raw,
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function price(array $r): NormalizedPrice
    {
        $code = (string) ($this->string($r, 'code', 'id') ?? '');
        $first = $this->firstCurrency($r);

        return new NormalizedPrice(
            sourceId: $code,
            planSourceId: $code,
            currency: $this->currency($this->string($first, 'currency') ?? $this->string($r, 'currency')),
            // Decimal major units → minor.
            amountMinor: $this->minorFromMajor($first, 'unit_amount')
                ?? $this->minorFromMajor($r, 'unit_amount')
                ?? $this->minorFromMinor($r, 'unit_amount_in_cents')
                ?? 0,
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /**
     * The first per-currency price entry (`currencies[0]`), or an empty record (flat pricing).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function firstCurrency(array $r): array
    {
        $currencies = $this->dig($r, 'currencies');

        if (is_array($currencies) && isset($currencies[0])) {
            return $this->asRecord($currencies[0]);
        }

        return [];
    }

    /** @param array<string, mixed> $r */
    private function coupon(array $r): NormalizedCoupon
    {
        $type = strtolower((string) ($this->string($r, 'discount_type') ?? ''));
        $isPercent = $type === 'percent' || $type === 'percentage';

        return new NormalizedCoupon(
            sourceId: (string) ($this->string($r, 'id', 'code') ?? ''),
            code: (string) ($this->string($r, 'code') ?? ''),
            name: $this->string($r, 'name'),
            kind: $isPercent ? 'percent' : 'fixed',
            percentOff: $isPercent ? $this->int($r, 'discount_percent') : null,
            // Recurly coupon fixed amounts ARE minor units (discount_in_cents) — unlike its plans.
            amountOffMinor: $isPercent ? null : $this->minorFromMinor($r, 'discount_in_cents'),
            currency: $this->currency($this->string($r, 'currency')),
            duration: $this->duration((string) ($this->string($r, 'duration', 'temporal_unit') ?? 'single_use')),
            durationInPeriods: $this->int($r, 'temporal_amount'),
            maxRedemptions: $this->int($r, 'max_redemptions'),
            redeemBy: $this->timestamp($r, 'redeem_by_date'),
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function customer(array $r): NormalizedCustomer
    {
        $code = (string) ($this->string($r, 'code', 'id') ?? '');
        $name = trim(implode(' ', array_filter([
            $this->string($r, 'first_name'),
            $this->string($r, 'last_name'),
        ])));

        return new NormalizedCustomer(
            sourceId: $code,
            name: $name !== '' ? $name : (string) ($this->string($r, 'company', 'email') ?? $code),
            email: $this->string($r, 'email'),
            currency: null,
            country: $this->string($r, 'address.country', 'country'),
            taxId: $this->string($r, 'vat_number', 'tax_id'),
            createdAt: $this->timestamp($r, 'created_at'),
        );
    }

    /** @param array<string, mixed> $r */
    private function subscription(array $r): NormalizedSubscription
    {
        return new NormalizedSubscription(
            sourceId: (string) ($this->string($r, 'uuid', 'id') ?? ''),
            customerSourceId: (string) ($this->string($r, 'account.code', 'account_code') ?? ''),
            planSourceId: (string) ($this->string($r, 'plan.code', 'plan_code') ?? ''),
            status: $this->status((string) ($this->string($r, 'state', 'status') ?? 'active')),
            seats: $this->int($r, 'quantity') ?? 1,
            currency: $this->currency($this->string($r, 'currency')),
            currentPeriodStart: $this->timestamp($r, 'current_period_started_at', 'current_term_started_at'),
            currentPeriodEnd: $this->timestamp($r, 'current_period_ends_at', 'current_term_ends_at'),
            trialEndsAt: $this->timestamp($r, 'trial_ends_at'),
            canceledAt: $this->timestamp($r, 'canceled_at', 'expires_at'),
            createdAt: $this->timestamp($r, 'created_at', 'activated_at'),
            couponCode: $this->string($r, 'coupon_redemptions.0.coupon.code', 'coupon_code'),
        );
    }

    /** @param array<string, mixed> $r */
    private function invoice(array $r): NormalizedInvoice
    {
        return new NormalizedInvoice(
            sourceId: (string) ($this->string($r, 'uuid', 'id', 'number') ?? ''),
            customerSourceId: (string) ($this->string($r, 'account.code', 'account_code') ?? ''),
            subscriptionSourceId: $this->string($r, 'subscription.uuid', 'subscription_id'),
            number: (string) ($this->string($r, 'number', 'id') ?? ''),
            currency: $this->currency($this->string($r, 'currency')),
            // Recurly invoice amounts are decimal major units.
            subtotalMinor: $this->minorFromMajor($r, 'subtotal') ?? 0,
            taxMinor: $this->minorFromMajor($r, 'tax') ?? 0,
            totalMinor: $this->minorFromMajor($r, 'total') ?? 0,
            status: $this->invoiceStatus((string) ($this->string($r, 'state', 'status') ?? 'open')),
            issuedAt: $this->timestamp($r, 'created_at', 'date'),
            periodStart: $this->timestamp($r, 'line_items.0.start_date'),
            periodEnd: $this->timestamp($r, 'line_items.0.end_date'),
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
                    description: (string) ($this->string($line, 'description') ?? 'Line item'),
                    quantity: $this->int($line, 'quantity') ?? 1,
                    unitAmountMinor: $this->minorFromMajor($line, 'unit_amount') ?? 0,
                    amountMinor: $this->minorFromMajor($line, 'amount') ?? 0,
                );
            }
        }

        return $lines;
    }

    private function status(string $state): string
    {
        return match (strtolower($state)) {
            'expired' => 'canceled',
            'canceled', 'cancelled' => 'canceled',
            'paused' => 'paused',
            default => 'active',
        };
    }

    private function invoiceStatus(string $state): string
    {
        return match (strtolower($state)) {
            'paid', 'collected' => 'paid',
            'voided' => 'void',
            'failed', 'past_due', 'open', 'pending' => 'open',
            default => strtolower($state),
        };
    }

    private function duration(string $type): string
    {
        return match (strtolower($type)) {
            'single_use', 'once' => 'once',
            'temporal', 'months', 'days' => 'repeating',
            'forever' => 'forever',
            default => strtolower($type),
        };
    }
}
