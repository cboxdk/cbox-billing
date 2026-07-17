<x-mail.shell
    title="Trial ending"
    :preheader="'Your '.$planName.' trial ends on '.$endsAtLabel"
    eyebrow="Trial reminder"
    heading="Your free trial is ending soon"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    <p style="margin:0 0 8px;">Your free trial of {{ $planName }} ends on {{ $endsAtLabel }}. When it does, your subscription starts automatically and we'll charge your first payment — no action is needed to continue.</p>

    <x-mail.summary
        :rows="[
            'Plan' => $planName,
            'Trial ends' => $endsAtLabel,
        ]"
        :total="['First charge' => $amountFormatted]"
    />

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">Not ready to continue? You can cancel any time before {{ $endsAtLabel }} from your billing portal and you won't be charged.</p>
</x-mail.shell>
