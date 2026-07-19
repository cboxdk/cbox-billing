<?php

declare(strict_types=1);

namespace App\Billing\Notifications;

use App\Models\MailTemplate;

/**
 * The catalog of transactional-email event types the billing lifecycle emits. Each case is
 * the stable key a {@see MailTemplate} override is filed under and a shipped
 * default template exists for (EN + DA). The enum is the single source of truth for the
 * console editor: its {@see label()}, {@see description()}, {@see variables()} (the
 * available-variable reference shown next to the editor) and {@see sampleVariables()} (the
 * synthetic bag the live preview renders with) all hang off the case.
 *
 * Adding a locale is a drop-in (a new resources/mail-templates/{locale}.php file); adding an
 * event type is a new case here + its default entry in each locale file.
 */
enum MailEventType: string
{
    case InvoiceIssued = 'invoice_issued';
    case PaymentReceipt = 'payment_receipt';
    case PaymentFailed = 'payment_failed';
    case PaymentRetry = 'payment_retry';
    case TrialEnding = 'trial_ending';
    case RenewalReminder = 'renewal_reminder';
    case SubscriptionChanged = 'subscription_changed';
    case LicenseDelivered = 'license_delivered';
    case PlanRetiring = 'plan_retiring';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'Invoice issued',
            self::PaymentReceipt => 'Payment receipt',
            self::PaymentFailed => 'Payment past due / dunning',
            self::PaymentRetry => 'Payment retry',
            self::TrialEnding => 'Trial ending',
            self::RenewalReminder => 'Renewal reminder',
            self::SubscriptionChanged => 'Subscription changed',
            self::LicenseDelivered => 'License delivered',
            self::PlanRetiring => 'Plan retiring',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'Sent when a period invoice is finalized (also reused when an operator re-sends an invoice).',
            self::PaymentReceipt => 'Sent when a settled-payment webhook marks an invoice paid.',
            self::PaymentFailed => 'A dunning step — notifies the account of a past-due balance (and any suspension).',
            self::PaymentRetry => 'A renewal charge failed and the smart-retry schedule is chasing it (or has given up).',
            self::TrialEnding => 'Ahead of a trial converting to a paid subscription.',
            self::RenewalReminder => 'Ahead of a term renewal.',
            self::SubscriptionChanged => 'Confirms a plan change, scheduled cancellation, or cancellation.',
            self::LicenseDelivered => 'Delivers an issued/reissued on-prem license key and install notes.',
            self::PlanRetiring => 'Warns a subscriber their plan is retiring and a choice is due at renewal.',
        };
    }

    /**
     * The variables the template body/subject may interpolate for this event, each with a
     * human description and a representative sample value (booleans as bool). This is both the
     * editor's available-variable reference and the seed for {@see sampleVariables()}.
     *
     * @return array<string, array{description: string, sample: string|bool|int}>
     */
    public function variables(): array
    {
        return match ($this) {
            self::InvoiceIssued => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'invoice_number' => ['description' => 'The finalized invoice number', 'sample' => 'CBOX-DK-2026-000148'],
                'amount_formatted' => ['description' => 'The taxed total, formatted for the locale', 'sample' => 'DKK 1.240,00'],
                'period_label' => ['description' => 'The billing period the invoice covers', 'sample' => '1 Jul 2026 – 31 Jul 2026'],
                'issued_at_label' => ['description' => 'The issue date', 'sample' => '1 Jul 2026'],
                'due_at_label' => ['description' => 'The payment due date', 'sample' => '15 Jul 2026'],
            ],
            self::PaymentReceipt => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'invoice_number' => ['description' => 'The now-settled invoice number', 'sample' => 'CBOX-DK-2026-000148'],
                'amount_formatted' => ['description' => 'The amount paid, formatted for the locale', 'sample' => 'DKK 1.240,00'],
                'paid_at_label' => ['description' => 'The settlement date', 'sample' => '3 Jul 2026'],
                'gateway_reference' => ['description' => "The gateway's settlement reference (may be empty)", 'sample' => 'pi_3Qx9Za2eZvKY'],
            ],
            self::PaymentFailed => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'amount_due_formatted' => ['description' => 'The outstanding balance, formatted for the locale', 'sample' => 'DKK 1.240,00'],
                'suspended' => ['description' => 'Whether access has been suspended for non-payment', 'sample' => false],
                'oldest_due_label' => ['description' => 'The oldest past-due date (may be empty)', 'sample' => '15 Jun 2026'],
            ],
            self::PaymentRetry => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'invoice_number' => ['description' => 'The invoice the charge is for', 'sample' => 'CBOX-DK-2026-000148'],
                'amount_formatted' => ['description' => 'The amount due, formatted for the locale', 'sample' => 'DKK 1.240,00'],
                'attempt' => ['description' => 'The retry attempt number (0 = the first failure notice)', 'sample' => 1],
                'max_attempts' => ['description' => 'The total number of scheduled attempts', 'sample' => 4],
                'next_attempt_label' => ['description' => 'When the next attempt runs (may be empty)', 'sample' => '9 Jul 2026'],
                'exhausted' => ['description' => 'Whether retries are exhausted (the final notice)', 'sample' => false],
            ],
            self::TrialEnding => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'plan_name' => ['description' => 'The plan the trial is for', 'sample' => 'Team'],
                'ends_at_label' => ['description' => 'When the trial ends', 'sample' => '20 Jul 2026'],
                'amount_formatted' => ['description' => 'The first charge, formatted for the locale', 'sample' => 'DKK 900,00'],
            ],
            self::RenewalReminder => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'plan_name' => ['description' => 'The renewing plan', 'sample' => 'Team'],
                'renews_at_label' => ['description' => 'When the subscription renews', 'sample' => '1 Aug 2026'],
                'amount_formatted' => ['description' => 'The recurring amount, formatted for the locale', 'sample' => 'DKK 900,00'],
            ],
            self::SubscriptionChanged => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'change_type' => ['description' => 'One of plan_change, canceled, cancel_scheduled', 'sample' => 'plan_change'],
                'is_plan_change' => ['description' => 'True when the change is a plan change', 'sample' => true],
                'is_canceled' => ['description' => 'True when the subscription was canceled outright', 'sample' => false],
                'is_cancel_scheduled' => ['description' => 'True when a cancellation is scheduled for period end', 'sample' => false],
                'plan_name' => ['description' => 'The (new) plan', 'sample' => 'Team'],
                'previous_plan_name' => ['description' => 'The prior plan on a change (may be empty)', 'sample' => 'Starter'],
                'effective_at_label' => ['description' => 'When the change takes effect (may be empty)', 'sample' => '1 Aug 2026'],
            ],
            self::LicenseDelivered => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'license_key' => ['description' => 'The issued license key', 'sample' => 'CBOXLIC.eyJwbGFuIjoiZW50ZXJwcmlzZS1vbnByZW0i.SIGNED'],
                'plan_label' => ['description' => 'The licensed plan', 'sample' => 'Enterprise (on-prem)'],
                'deployment_id' => ['description' => 'The deployment the license is bound to', 'sample' => 'dpl_9f2c1a'],
                'expires_at_label' => ['description' => 'When the license expires', 'sample' => '18 Jul 2027'],
                'reissued' => ['description' => 'Whether this is a renewal/reissue of an existing key', 'sample' => false],
            ],
            self::PlanRetiring => [
                'organization_name' => ['description' => "The customer organization's name", 'sample' => 'Northwind Traders'],
                'plan_name' => ['description' => 'The retiring plan', 'sample' => 'Team (legacy)'],
                'retires_at_label' => ['description' => 'When the plan retires', 'sample' => '1 Sep 2026'],
                'renewal_due_label' => ['description' => 'The next renewal — the deadline to choose', 'sample' => '1 Aug 2026'],
                'default_successor_name' => ['description' => 'The default plan they fall to (may be empty)', 'sample' => 'Team'],
            ],
        };
    }

    /**
     * The synthetic variable bag the console live-preview renders with when no real record is
     * chosen — the sample value of every declared variable.
     *
     * @return array<string, string|bool|int>
     */
    public function sampleVariables(): array
    {
        return array_map(static fn (array $meta): string|bool|int => $meta['sample'], $this->variables());
    }
}
