<x-mail.shell
    title="Plan retiring"
    :preheader="$planName.' retires on '.$retiresAtLabel"
    eyebrow="Plan update"
    heading="Your plan is being retired"
    accent="#b3651f"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    <p style="margin:0 0 8px;">{{ $planName }} is being retired on {{ $retiresAtLabel }}. Your subscription keeps working until then — but at your next renewal on {{ $renewalDueLabel }}, you'll need to move to a new plan.</p>

    <x-mail.summary
        :rows="array_filter([
            'Retiring plan' => $planName,
            'Retires on' => $retiresAtLabel,
            'Choose by (next renewal)' => $renewalDueLabel,
            'Default plan' => $defaultSuccessorName,
        ])"
    />

    @if ($defaultSuccessorName)
        <p style="margin:16px 0 0;">If you do nothing, we'll move you to <strong>{{ $defaultSuccessorName }}</strong> at your next renewal. You can also pick a different plan, or cancel, from your billing portal before then.</p>
    @else
        <p style="margin:16px 0 0;">Please choose a new plan from your billing portal before {{ $renewalDueLabel }} — your subscription can't renew on the retired plan.</p>
    @endif
</x-mail.shell>
