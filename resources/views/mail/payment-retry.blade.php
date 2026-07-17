<x-mail.shell
    title="Payment failed"
    :preheader="$exhausted ? 'We were unable to collect payment on '.$invoiceNumber : 'We\'ll retry your payment for '.$invoiceNumber"
    eyebrow="Payment issue"
    accent="#b3273f"
    :heading="$exhausted ? 'We couldn\'t process your payment' : 'Your payment didn\'t go through'"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    @if ($exhausted)
        <p style="margin:0 0 8px;">We tried {{ $maxAttempts }} {{ $maxAttempts === 1 ? 'time' : 'times' }} to collect payment for invoice {{ $invoiceNumber }} but weren't able to. To avoid losing access, please update your payment method and settle the outstanding balance.</p>
        <x-mail.summary
            :rows="['Invoice' => $invoiceNumber, 'Status' => 'Retries exhausted']"
            :total="['Amount due' => $amountFormatted]"
        />
    @else
        <p style="margin:0 0 8px;">We weren't able to charge your payment method for invoice {{ $invoiceNumber }}. Your service continues for now — we'll automatically try again{{ $nextAttemptLabel ? ' on '.$nextAttemptLabel : ' shortly' }}.</p>
        <x-mail.summary
            :rows="array_filter([
                'Invoice' => $invoiceNumber,
                'Next attempt' => $nextAttemptLabel,
            ])"
            :total="['Amount due' => $amountFormatted]"
        />
        <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">You can avoid further retries by updating your payment method in your billing portal.</p>
    @endif
</x-mail.shell>
