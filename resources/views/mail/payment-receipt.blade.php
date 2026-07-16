<x-mail.shell
    title="Payment received"
    :preheader="'We received '.$amountFormatted.' for invoice '.$invoiceNumber"
    eyebrow="Receipt"
    accent="#1f8a4c"
    heading="Payment received"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    <p style="margin:0 0 8px;">Thank you — we have received your payment and invoice {{ $invoiceNumber }} is now settled.</p>

    <x-mail.summary
        :rows="array_filter([
            'Invoice' => $invoiceNumber,
            'Paid on' => $paidAtLabel,
            'Reference' => $gatewayReference,
        ])"
        :total="['Amount paid' => $amountFormatted]"
    />

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">This receipt confirms settlement. No further action is needed.</p>
</x-mail.shell>
