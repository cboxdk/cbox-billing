<x-mail.shell
    title="Payment reminder"
    :preheader="$suspended ? 'Your account has been suspended for non-payment' : 'A payment on your account is past due'"
    :eyebrow="$suspended ? 'Account suspended' : 'Payment reminder'"
    :accent="$suspended ? '#c0392b' : '#b5730f'"
    :heading="$suspended ? 'Your account has been suspended' : 'A payment is past due'"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    @if ($suspended)
        <p style="margin:0 0 8px;">We were unable to collect payment on your account, and access has now been suspended. To restore access, please settle the outstanding balance in full.</p>
    @else
        <p style="margin:0 0 8px;">We noticed a payment on your account is past due. Please settle the outstanding balance to avoid an interruption to your service.</p>
    @endif

    <x-mail.summary
        :rows="array_filter([
            'Oldest past-due' => $oldestDueLabel,
        ])"
        :total="['Outstanding' => $amountDueFormatted]"
    />

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">If you have already paid, please disregard this notice — it may cross with your payment.</p>
</x-mail.shell>
