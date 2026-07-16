<x-mail.shell
    title="Subscription renewal"
    :preheader="'Your '.$planName.' subscription renews on '.$renewsAtLabel"
    eyebrow="Renewal reminder"
    heading="Your subscription renews soon"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    <p style="margin:0 0 8px;">This is a reminder that your {{ $planName }} subscription is set to renew. No action is needed — it will continue automatically.</p>

    <x-mail.summary
        :rows="[
            'Plan' => $planName,
            'Renews on' => $renewsAtLabel,
        ]"
        :total="['Recurring amount' => $amountFormatted]"
    />

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">Want to make a change before it renews? You can update or cancel your plan from your billing portal.</p>
</x-mail.shell>
