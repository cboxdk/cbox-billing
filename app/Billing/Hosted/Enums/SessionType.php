<?php

declare(strict_types=1);

namespace App\Billing\Hosted\Enums;

/**
 * The kind of hosted session (ADR-0009 Path A): a one-shot {@see SessionType::Checkout}
 * that collects payment for a plan, or a re-usable {@see SessionType::Portal} where a
 * customer manages an existing subscription, its payment method and its invoices.
 */
enum SessionType: string
{
    case Checkout = 'checkout';
    case Portal = 'portal';
}
