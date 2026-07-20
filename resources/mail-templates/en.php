<?php

declare(strict_types=1);

/*
 * Shipped English default templates for every transactional-email event type. These are the
 * never-dead-end floor of the resolution chain: a DB row overrides them per (event, locale,
 * seller); absent one, these render. Authored in the restricted, sandboxed mustache syntax —
 * NOT Blade — so nothing here is evaluated as PHP. The body renders inside the branded layout
 * (resources/views/emails/layout.blade.php), which supplies the header, footer and outer
 * shell; the reserved `brand_color` variable carries the seller's accent into the body.
 */

$eyebrow = static fn (string $text): string => '<div style="font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:{{ brand_color }};margin:0 0 10px;">'.$text.'</div>';
$heading = static fn (string $text): string => '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-.015em;color:#1a1714;">'.$text.'</h1>';
$p = static fn (string $text): string => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#44403a;">'.$text.'</p>';
$note = static fn (string $text): string => '<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#6b6560;">'.$text.'</p>';
$row = static fn (string $label, string $value): string => '<tr><td style="padding:11px 16px;border-bottom:1px solid #efe9df;font-size:13px;color:#6b6560;">'.$label.'</td><td align="right" style="padding:11px 16px;border-bottom:1px solid #efe9df;font-size:14px;font-weight:600;color:#1a1714;">'.$value.'</td></tr>';
$total = static fn (string $label, string $value): string => '<tr><td style="padding:13px 16px;background:#faf7f2;font-size:13px;font-weight:600;color:#1a1714;">'.$label.'</td><td align="right" style="padding:13px 16px;background:#faf7f2;font-size:17px;font-weight:700;color:#1a1714;">'.$value.'</td></tr>';
$summaryOpen = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;border:1px solid #e5ded2;border-radius:10px;overflow:hidden;">';
$summaryClose = '</table>';

return [
    'invoice_issued' => [
        'subject' => 'Invoice {{ invoice_number }} — {{ amount_formatted }} due {{ due_at_label }}',
        'body' => $eyebrow('Invoice issued').$heading('Invoice {{ invoice_number }}')
            .$p('Hi {{ organization_name }},')
            .$p('A new invoice has been issued for your subscription covering {{ period_label }}.')
            .$summaryOpen
            .$row('Invoice', '{{ invoice_number }}')
            .$row('Billing period', '{{ period_label }}')
            .$row('Issued', '{{ issued_at_label }}')
            .$row('Due', '{{ due_at_label }}')
            .$total('Amount due', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Payment instructions are on the invoice. If you have already paid, please disregard this notice.'),
    ],

    'payment_receipt' => [
        'subject' => 'Payment received for invoice {{ invoice_number }}',
        'body' => $eyebrow('Receipt').$heading('Payment received')
            .$p('Hi {{ organization_name }},')
            .$p('Thank you — we have received your payment and invoice {{ invoice_number }} is now settled.')
            .$summaryOpen
            .$row('Invoice', '{{ invoice_number }}')
            .$row('Paid on', '{{ paid_at_label }}')
            .'{{#if gateway_reference }}'.$row('Reference', '{{ gateway_reference }}').'{{/if}}'
            .$total('Amount paid', '{{ amount_formatted }}')
            .$summaryClose
            .$note('This receipt confirms settlement. No further action is needed.'),
    ],

    'payment_failed' => [
        'subject' => '{{#if suspended }}Your account has been suspended{{else}}A payment on your account is past due{{/if}}',
        'body' => '{{#if suspended }}'.$eyebrow('Account suspended').$heading('Your account has been suspended').'{{else}}'.$eyebrow('Payment reminder').$heading('A payment is past due').'{{/if}}'
            .$p('Hi {{ organization_name }},')
            .'{{#if suspended }}'.$p('We were unable to collect payment on your account, and access has now been suspended. To restore access, please settle the outstanding balance in full.').'{{else}}'.$p('We noticed a payment on your account is past due. Please settle the outstanding balance to avoid an interruption to your service.').'{{/if}}'
            .$summaryOpen
            .'{{#if oldest_due_label }}'.$row('Oldest past-due', '{{ oldest_due_label }}').'{{/if}}'
            .$total('Outstanding', '{{ amount_due_formatted }}')
            .$summaryClose
            .$note('If you have already paid, please disregard this notice — it may cross with your payment.'),
    ],

    'payment_retry' => [
        'subject' => '{{#if requires_new_method }}Action needed: update your payment method for {{ invoice_number }}{{else}}{{#if exhausted }}We couldn’t process your payment for {{ invoice_number }}{{else}}{{#if needs_action }}Confirm your payment for {{ invoice_number }}{{else}}Your payment for {{ invoice_number }} didn’t go through{{/if}}{{/if}}{{/if}}',
        // The decline category selects the message: a hard decline (requires_new_method) asks for
        // a new card and never promises a retry; needs_action asks the customer to authenticate;
        // exhausted is the give-up notice; otherwise it is the ordinary "we’ll try again" notice.
        'body' => '{{#if requires_new_method }}'
            .$eyebrow('Action needed').$heading('Please update your payment method')
            .$p('Hi {{ organization_name }},')
            .$p('Your payment method was declined for invoice {{ invoice_number }} and can’t be charged again — this usually means the card was reported lost or stolen, has expired, or was closed. To keep your service, please add a new payment method and settle the outstanding balance.')
            .$summaryOpen.$row('Invoice', '{{ invoice_number }}').$row('Status', 'A new payment method is required').$total('Amount due', '{{ amount_formatted }}').$summaryClose
            .$note('Once a new method is on file we’ll collect the balance automatically.')
            .'{{else}}{{#if exhausted }}'
            .$eyebrow('Payment issue').$heading('We couldn’t process your payment')
            .$p('Hi {{ organization_name }},')
            .$p('We tried {{ max_attempts }} times to collect payment for invoice {{ invoice_number }} but weren’t able to. To avoid losing access, please update your payment method and settle the outstanding balance.')
            .$summaryOpen.$row('Invoice', '{{ invoice_number }}').$row('Status', 'Retries exhausted').$total('Amount due', '{{ amount_formatted }}').$summaryClose
            .'{{else}}{{#if needs_action }}'
            .$eyebrow('Confirmation needed').$heading('Confirm your payment')
            .$p('Hi {{ organization_name }},')
            .$p('Your bank needs you to confirm the payment for invoice {{ invoice_number }} before it can go through. Please authenticate the payment from your billing portal to complete it{{#if next_attempt_label }} — we’ll try again on {{ next_attempt_label }}{{/if}}.')
            .$summaryOpen.$row('Invoice', '{{ invoice_number }}').'{{#if next_attempt_label }}'.$row('Next attempt', '{{ next_attempt_label }}').'{{/if}}'.$total('Amount due', '{{ amount_formatted }}').$summaryClose
            .$note('Authenticating now avoids any interruption to your service.')
            .'{{else}}'
            .$eyebrow('Payment issue').$heading('Your payment didn’t go through')
            .$p('Hi {{ organization_name }},')
            .$p('We weren’t able to charge your payment method for invoice {{ invoice_number }}. Your service continues for now — we’ll automatically try again{{#if next_attempt_label }} on {{ next_attempt_label }}{{/if}}.')
            .$summaryOpen.$row('Invoice', '{{ invoice_number }}').'{{#if next_attempt_label }}'.$row('Next attempt', '{{ next_attempt_label }}').'{{/if}}'.$total('Amount due', '{{ amount_formatted }}').$summaryClose
            .$note('You can avoid further retries by updating your payment method in your billing portal.')
            .'{{/if}}{{/if}}{{/if}}',
    ],

    'trial_ending' => [
        'subject' => 'Your {{ plan_name }} trial ends on {{ ends_at_label }}',
        'body' => $eyebrow('Trial reminder').$heading('Your free trial is ending soon')
            .$p('Hi {{ organization_name }},')
            .$p('Your free trial of {{ plan_name }} ends on {{ ends_at_label }}. When it does, your subscription starts automatically and we’ll charge your first payment — no action is needed to continue.')
            .$summaryOpen
            .$row('Plan', '{{ plan_name }}')
            .$row('Trial ends', '{{ ends_at_label }}')
            .$total('First charge', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Not ready to continue? You can cancel any time before {{ ends_at_label }} from your billing portal and you won’t be charged.'),
    ],

    'renewal_reminder' => [
        'subject' => 'Your {{ plan_name }} subscription renews on {{ renews_at_label }}',
        'body' => $eyebrow('Renewal reminder').$heading('Your subscription renews soon')
            .$p('Hi {{ organization_name }},')
            .$p('This is a reminder that your {{ plan_name }} subscription is set to renew. No action is needed — it will continue automatically.')
            .$summaryOpen
            .$row('Plan', '{{ plan_name }}')
            .$row('Renews on', '{{ renews_at_label }}')
            .$total('Recurring amount', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Want to make a change before it renews? You can update or cancel your plan from your billing portal.'),
    ],

    'subscription_changed' => [
        'subject' => '{{#if is_canceled }}Your subscription has been canceled{{/if}}{{#if is_cancel_scheduled }}Your subscription is scheduled to cancel{{/if}}{{#if is_plan_change }}Your plan has been updated to {{ plan_name }}{{/if}}',
        'body' => '{{#if is_canceled }}'.$eyebrow('Cancellation').$heading('Your subscription has been canceled')
            .$p('Hi {{ organization_name }},')
            .$p('Your {{ plan_name }} subscription has been canceled and is now closed. We’re sorry to see you go.')
            .$summaryOpen.$row('Plan', '{{ plan_name }}').$row('Status', 'Canceled').$summaryClose
            .'{{/if}}{{#if is_cancel_scheduled }}'.$eyebrow('Cancellation').$heading('Your cancellation is scheduled')
            .$p('Hi {{ organization_name }},')
            .$p('Your {{ plan_name }} subscription is scheduled to cancel at the end of the current period. Until then, your service continues as normal.')
            .$summaryOpen.$row('Plan', '{{ plan_name }}').'{{#if effective_at_label }}'.$row('Cancels on', '{{ effective_at_label }}').'{{/if}}'.$summaryClose
            .'{{/if}}{{#if is_plan_change }}'.$eyebrow('Plan change').$heading('Your plan has been updated')
            .$p('Hi {{ organization_name }},')
            .$p('Your subscription has been changed {{#if previous_plan_name }}from {{ previous_plan_name }} {{/if}}to {{ plan_name }}.')
            .$summaryOpen.'{{#if previous_plan_name }}'.$row('Previous plan', '{{ previous_plan_name }}').'{{/if}}'.$row('New plan', '{{ plan_name }}').'{{#if effective_at_label }}'.$row('Effective', '{{ effective_at_label }}').'{{/if}}'.$summaryClose
            .'{{/if}}'
            .$note('You can review your subscription any time from your billing portal.'),
    ],

    'license_delivered' => [
        'subject' => '{{#if reissued }}Your renewed Cbox license key{{else}}Your Cbox license key{{/if}}',
        'body' => '{{#if reissued }}'.$eyebrow('License renewed').$heading('Your renewed license key').'{{else}}'.$eyebrow('License issued').$heading('Your license key').'{{/if}}'
            .$p('Hi {{ organization_name }},')
            .$p('Your {{ plan_label }} on-prem license {{#if reissued }}has been renewed{{else}}is ready{{/if}}. Copy the key below into your self-hosted deployment’s environment as <code style="font-family:\'SF Mono\',Menlo,monospace;font-size:13px;background:#f3ede4;padding:1px 5px;border-radius:4px;">CBOX_ID_LICENSE_KEY</code>.')
            .$summaryOpen
            .$row('Plan', '{{ plan_label }}')
            .$row('Deployment', '{{ deployment_id }}')
            .$row('Valid until', '{{ expires_at_label }}')
            .$summaryClose
            .'<p style="margin:18px 0 6px;font-size:13px;font-weight:600;color:#1a1714;">CBOX_ID_LICENSE_KEY</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:14px 16px;background:#1a1714;border-radius:10px;font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:#faf7f2;word-break:break-all;">{{ license_key }}</td></tr></table>'
            .$note('This key is valid until {{ expires_at_label }}. We’ll send a fresh key when your license renews.'),
    ],

    'plan_retiring' => [
        'subject' => '{{ plan_name }} is retiring on {{ retires_at_label }}',
        'body' => $eyebrow('Plan update').$heading('Your plan is being retired')
            .$p('Hi {{ organization_name }},')
            .$p('{{ plan_name }} is being retired on {{ retires_at_label }}. Your subscription keeps working until then — but at your next renewal on {{ renewal_due_label }}, you’ll need to move to a new plan.')
            .$summaryOpen
            .$row('Retiring plan', '{{ plan_name }}')
            .$row('Retires on', '{{ retires_at_label }}')
            .$row('Choose by (next renewal)', '{{ renewal_due_label }}')
            .'{{#if default_successor_name }}'.$row('Default plan', '{{ default_successor_name }}').'{{/if}}'
            .$summaryClose
            .'{{#if default_successor_name }}'.$note('If you do nothing, we’ll move you to <strong>{{ default_successor_name }}</strong> at your next renewal. You can also pick a different plan, or cancel, from your billing portal before then.').'{{else}}'.$note('Please choose a new plan from your billing portal before {{ renewal_due_label }} — your subscription can’t renew on the retired plan.').'{{/if}}',
    ],

    'usage_alert' => [
        'subject' => 'You’ve used {{ usage_percent }}% of your {{ meter_name }} allowance',
        'body' => $eyebrow('Usage alert').$heading('You’re approaching your {{ meter_name }} limit')
            .$p('Hi {{ organization_name }},')
            .$p('Your {{ meter_name }} usage has reached {{ threshold_percent }}% of the amount included with your plan this period. Once you pass the included allowance, further usage may be billed as overage.')
            .$summaryOpen
            .$row('Metered usage', '{{ meter_name }}')
            .$row('Used so far', '{{ used_formatted }}')
            .$row('Included allowance', '{{ allowance_formatted }}')
            .$total('Current usage', '{{ usage_percent }}%')
            .$summaryClose
            .$note('Your allowance resets on {{ period_end_label }}. You can review usage any time from your billing portal.'),
    ],
];
