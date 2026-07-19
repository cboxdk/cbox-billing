<?php

declare(strict_types=1);

namespace App\Billing\Audit\Support;

use App\Models\OperatorAuditEvent;
use Illuminate\Support\Facades\Route;

/**
 * Resolves an audit event's target to a console URL, so each event cross-links to the resource
 * it affected. The mapping is by target type; an unknown or unroutable type yields null (the
 * console renders it as plain text rather than a dead link).
 */
class AuditTargetLink
{
    public static function for(OperatorAuditEvent $event): ?string
    {
        $type = $event->target_type;
        $id = $event->target_id;

        if ($type === null || $id === null) {
            return null;
        }

        return match ($type) {
            'organization' => self::route('billing.customers.show', $id),
            'invoice' => self::route('billing.invoices.show', $id),
            'creditnote', 'credit_note' => self::route('billing.credit-notes.show', $id),
            'subscription' => self::route('billing.subscriptions.show', $id),
            default => null,
        };
    }

    /** Build a URL only when the named route actually exists in this deployment. */
    private static function route(string $name, string $id): ?string
    {
        return Route::has($name) ? route($name, $id) : null;
    }
}
