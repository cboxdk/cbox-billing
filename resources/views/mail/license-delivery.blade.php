<x-mail.shell
    title="Your Cbox license key"
    :preheader="$reissued ? 'Your renewed license key is ready' : 'Your license key is ready'"
    :eyebrow="$reissued ? 'License renewed' : 'License issued'"
    :heading="$reissued ? 'Your renewed license key' : 'Your license key'"
    footerNote="Keep this key private — it authorizes your self-hosted deployment."
>
    <p style="margin:0 0 16px;">Hi {{ $organizationName }},</p>

    <p style="margin:0 0 8px;">Your {{ $planLabel }} on-prem license {{ $reissued ? 'has been renewed' : 'is ready' }}. Copy the key below into your self-hosted deployment's environment as <code style="font-family:'SF Mono',Menlo,monospace; font-size:13px; background:#f3ede4; padding:1px 5px; border-radius:4px;">CBOX_ID_LICENSE_KEY</code>.</p>

    <x-mail.summary :rows="[
        'Plan' => $planLabel,
        'Deployment' => $deploymentId,
        'Valid until' => $expiresAtLabel,
    ]" />

    <p style="margin:18px 0 6px; font-size:13px; font-weight:600; color:#1a1714;">CBOX_ID_LICENSE_KEY</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding:14px 16px; background:#1a1714; border-radius:10px; font-family:'SF Mono',Menlo,Consolas,monospace; font-size:12px; line-height:1.5; color:#faf7f2; word-break:break-all;">{{ $licenseKey }}</td>
        </tr>
    </table>

    <p style="margin:18px 0 6px; font-size:14px; font-weight:600; color:#1a1714;">Install notes</p>
    <ol style="margin:0; padding-left:20px; font-size:14px; color:#44403a; line-height:1.7;">
        <li>Set <code style="font-family:'SF Mono',Menlo,monospace; font-size:13px; background:#f3ede4; padding:1px 5px; border-radius:4px;">CBOX_ID_LICENSE_KEY</code> to the value above in your deployment's <code style="font-family:'SF Mono',Menlo,monospace; font-size:13px; background:#f3ede4; padding:1px 5px; border-radius:4px;">.env</code>.</li>
        <li>Restart the application so it re-reads the license.</li>
        <li>The deployment verifies the key offline against the bundled public key — no call home is required.</li>
    </ol>

    <p style="margin:16px 0 0; font-size:13px; color:#6b6560;">This key is valid until {{ $expiresAtLabel }}. We'll send a fresh key when your license renews.</p>
</x-mail.shell>
