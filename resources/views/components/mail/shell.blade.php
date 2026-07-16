@props([
    'title' => 'Cbox Billing',
    'preheader' => null,
    'eyebrow' => null,
    'heading' => null,
    'accent' => '#2743b3',
    'footerNote' => null,
])
{{-- Shared branded HTML shell for Cbox Billing transactional email. Email clients strip
     external stylesheets, so the cbox design tokens are inlined here as hex — the same
     warm-cream / deep-blue palette the app renders with (public/cbox/tokens). --}}
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background:#faf7f2; color:#1a1714; -webkit-font-smoothing:antialiased;">
    @if ($preheader)
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#faf7f2; font-size:1px; line-height:1px;">{{ $preheader }}</div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf7f2;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%;">

                    <tr>
                        <td style="padding:4px 4px 20px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:18px; font-weight:700; letter-spacing:-0.01em; color:#1a1714;">
                            Cbox<span style="color:#2743b3;"> · Billing</span>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#ffffff; border:1px solid #e5ded2; border-radius:14px; overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:28px 32px 8px; font-family:'Helvetica Neue',Arial,sans-serif;">
                                        @if ($eyebrow)
                                            <div style="font-size:12px; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; color:{{ $accent }}; margin:0 0 10px;">{{ $eyebrow }}</div>
                                        @endif
                                        <h1 style="margin:0; font-size:22px; line-height:1.25; font-weight:700; letter-spacing:-0.015em; color:#1a1714;">{{ $heading ?? $title }}</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 32px 30px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:15px; line-height:1.6; color:#44403a;">
                                        {{ $slot }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 8px 4px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:12px; line-height:1.6; color:#8a847a;">
                            This is an automated message from Cbox Billing regarding your account.
                            @if ($footerNote)<br>{{ $footerNote }}@endif
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
