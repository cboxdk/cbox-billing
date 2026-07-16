<x-mail.shell
    title="Invoice {{ $invoiceNumber }}"
    :preheader="'Invoice '.$invoiceNumber.' — '.$amountFormatted.', due '.$dueAtLabel"
    eyebrow="Invoice issued"
    :heading="'Invoice '.$invoiceNumber"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    <p style="margin:0 0 8px;">A new invoice has been issued for your Cbox subscription covering {{ $periodLabel }}.</p>

    <x-mail.summary
        :rows="[
            'Invoice' => $invoiceNumber,
            'Billing period' => $periodLabel,
            'Issued' => $issuedAtLabel,
            'Due' => $dueAtLabel,
        ]"
        :total="['Amount due' => $amountFormatted]"
    />

    @if ($viewUrl)
        <x-mail.button :url="$viewUrl">View invoice</x-mail.button>
    @endif

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">Payment instructions are on the invoice. If you have already paid, please disregard this notice.</p>
</x-mail.shell>
