@php
    $isCancel = in_array($changeType, ['canceled', 'cancel_scheduled'], true);
    $preheaderText = match ($changeType) {
        'canceled' => 'Your subscription has been canceled',
        'cancel_scheduled' => 'Your subscription is scheduled to cancel',
        default => 'Your subscription has been changed to '.$planName,
    };
    $headingText = match ($changeType) {
        'canceled' => 'Your subscription has been canceled',
        'cancel_scheduled' => 'Your cancellation is scheduled',
        default => 'Your plan has been updated',
    };
@endphp
<x-mail.shell
    title="Subscription updated"
    :preheader="$preheaderText"
    :eyebrow="$isCancel ? 'Cancellation' : 'Plan change'"
    :heading="$headingText"
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    @if ($changeType === 'canceled')
        <p style="margin:0 0 8px;">Your {{ $planName }} subscription has been canceled and is now closed. We're sorry to see you go.</p>
        <x-mail.summary :rows="['Plan' => $planName, 'Status' => 'Canceled']" />
    @elseif ($changeType === 'cancel_scheduled')
        <p style="margin:0 0 8px;">Your {{ $planName }} subscription is scheduled to cancel at the end of the current period. Until then, your service continues as normal.</p>
        <x-mail.summary :rows="array_filter(['Plan' => $planName, 'Cancels on' => $effectiveAtLabel])" />
    @else
        <p style="margin:0 0 8px;">Your subscription has been changed {{ $previousPlanName ? 'from '.$previousPlanName.' ' : '' }}to {{ $planName }}.</p>
        <x-mail.summary :rows="array_filter([
            'Previous plan' => $previousPlanName,
            'New plan' => $planName,
            'Effective' => $effectiveAtLabel,
        ])" />
    @endif

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">You can review your subscription any time from your billing portal.</p>
</x-mail.shell>
