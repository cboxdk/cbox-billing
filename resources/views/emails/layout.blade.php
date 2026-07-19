@php
    /**
     * The branded, responsive, email-client-safe layout that wraps every transactional
     * template body. This is trusted application CODE (not authored from the DB): it renders
     * the seller's branding around $bodyHtml, which is ALREADY the output of the sandboxed
     * template renderer (values HTML-escaped), so echoing it raw here is safe.
     *
     * Email constraints honoured: no external stylesheet (all styles inline), table-based
     * layout, 600px max-width that degrades to 100% on mobile, web-safe font stack, and an
     * explicit light color-scheme with solid backgrounds so a dark-mode client can't wash the
     * card out.
     *
     * @var \App\Billing\Notifications\Branding\SellerBranding $branding
     * @var string $bodyHtml
     * @var string $subject
     * @var string $locale
     */
    $accent = $branding->brandColor;
    $address = $branding->footerAddress !== null ? trim($branding->footerAddress) : '';
@endphp
<!doctype html>
<html lang="{{ $locale }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0; padding:0; width:100%; background:#faf7f2; color:#1a1714; -webkit-font-smoothing:antialiased; -webkit-text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#faf7f2; font-size:1px; line-height:1px;">{{ $subject }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf7f2;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:100%;">

                    {{-- Brand header --}}
                    <tr>
                        <td style="padding:4px 4px 18px; font-family:'Helvetica Neue',Arial,sans-serif;">
                            @if ($branding->logoUrl)
                                <img src="{{ $branding->logoUrl }}" alt="{{ $branding->productName }}" height="28" style="height:28px; max-height:28px; width:auto; border:0; display:block;">
                            @else
                                <span style="font-size:18px; font-weight:700; letter-spacing:-0.01em; color:#1a1714;">{{ $branding->productName }}</span>
                            @endif
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background:#ffffff; border:1px solid #e5ded2; border-top:3px solid {{ $accent }}; border-radius:14px; overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:28px 32px 30px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:15px; line-height:1.6; color:#44403a;">
                                        {!! $bodyHtml !!}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:22px 8px 4px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:12px; line-height:1.7; color:#8a847a;">
                            <div style="font-weight:600; color:#6b6560;">{{ $branding->legalLine() }}</div>
                            @if ($address !== '')
                                <div>{!! nl2br(e($address)) !!}</div>
                            @endif
                            @if ($branding->supportUrl || $branding->supportEmail)
                                <div style="margin-top:8px;">
                                    {{ __('emails.support_prompt', [], $locale) }}
                                    @if ($branding->supportUrl)
                                        <a href="{{ $branding->supportUrl }}" style="color:{{ $accent }}; text-decoration:none; font-weight:600;">{{ __('emails.contact_support', [], $locale) }}</a>
                                    @elseif ($branding->supportEmail)
                                        <a href="mailto:{{ $branding->supportEmail }}" style="color:{{ $accent }}; text-decoration:none; font-weight:600;">{{ $branding->supportEmail }}</a>
                                    @endif
                                </div>
                            @endif
                            <div style="margin-top:8px;">{{ __('emails.automated', ['product' => $branding->productName], $locale) }}</div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
