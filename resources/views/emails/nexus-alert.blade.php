<x-mail::message>
# US economic nexus — action needed

The following {{ count($rows) === 1 ? 'state has' : 'states have' }} crossed an economic-nexus threshold for your default selling entity. **Triggered** means an obligation now exists and you should register; **Approaching** is a watch signal.

<x-mail::table>
| State | Status | Threshold | Progress |
| :---- | :----- | :-------- | :------- |
@foreach ($rows as $row)
| {{ $row['state'] }} | {{ $row['status'] }} | {{ $row['threshold'] }} | {{ $row['progress'] }} |
@endforeach
</x-mail::table>

@unless ($soleSalesChannel)
> **Multi-channel note.** These figures reflect only sales invoiced through this platform plus any external-channel sales you have recorded. Sales through channels you have not recorded also count toward each state's threshold — a state shown Approaching may already be Triggered once every channel is combined.
@endunless

This is an automated operations alert. Thresholds come from the us-tax-data dataset; sales from your own invoices and recorded external channels.
</x-mail::message>
