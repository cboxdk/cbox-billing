<?php

declare(strict_types=1);

namespace App\Billing\Audit\Contracts;

use App\Billing\Audit\ValueObjects\DsarBundle;
use App\Models\Organization;

/**
 * Assembles a data-subject access (DSAR) bundle for an organization: every dataset the subject
 * appears in — organizations, subscriptions, invoices (+ lines), credit notes, payments, usage,
 * coupons, seats, licenses, and the operator audit events about them — as a downloadable
 * archive of a manifest plus one file per dataset. The bundle is scoped strictly to the subject
 * (deny-by-default: a dataset that cannot be subject-scoped contributes nothing, never the whole
 * plane), and assembling one is itself an audited action.
 */
interface AssemblesDsarBundle
{
    /** Build the archive on disk for the given subject and plane, returning its descriptor. */
    public function build(Organization $organization, bool $livemode): DsarBundle;
}
