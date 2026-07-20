<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Enums;

use App\Billing\Approvals\ApprovableActionRegistry;
use App\Billing\Approvals\Contracts\BuildsApprovableAction;

/**
 * The typed catalog of operator actions that CAN be held behind a second-person approval.
 * Each case is a stable dotted `<resource>.<verb>` slug — the key the policy config
 * (`billing.approvals.actions.<slug>`) and the {@see ApprovableActionRegistry}
 * are keyed on. Adding a new approvable action means: one case here, one registered
 * {@see BuildsApprovableAction} factory, and a policy entry.
 *
 * The registry is deny-by-default: a type with no registered factory cannot be held OR
 * executed, so a config entry that references an unknown action fails closed rather than
 * silently letting the mutation through unapproved.
 */
enum ApprovalActionType: string
{
    /** Reverse an invoice as a credit note (money out). */
    case InvoiceRefund = 'invoice.refund';

    /** Grant or debit organization wallet credit (balance movement). */
    case WalletAdjust = 'wallet.adjust';

    /** Suspend a customer organization (account standing). */
    case CustomerSuspend = 'customer.suspend';

    /** Archive a plan — close it to new signups (catalog). */
    case PlanArchive = 'plan.archive';

    /** Create a coupon / standing discount (catalog). */
    case CouponCreate = 'coupon.create';

    /** Erase (pseudonymize) a data subject's PII, deleting certificate documents (GDPR RTBF). */
    case DataErase = 'data.erase';

    /** The coarse resource group, for the console filter and colour coding. */
    public function category(): string
    {
        return explode('.', $this->value)[0];
    }

    /** A short human label for the console. */
    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], [' · ', ' '], $this->value));
    }
}
