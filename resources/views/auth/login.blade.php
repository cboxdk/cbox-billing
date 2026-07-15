<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Cbox · Billing</title>
    <link rel="icon" href="{{ asset('cbox/assets/logo/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('cbox/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('cbox/cbox-app.css') }}">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { width: 100%; max-width: 380px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); box-shadow: var(--shadow-card); padding: 32px 28px; text-align: center; }
        .card img { height: 30px; margin-bottom: 22px; }
        .card h1 { font-family: var(--font-display); font-size: 19px; font-weight: 600; margin: 0 0 6px; letter-spacing: -0.01em; }
        .card p.sub { font-size: 13px; color: var(--muted-foreground); margin: 0 0 24px; }
        .card .cbx-btn { width: 100%; height: 40px; font-size: 14px; }
        .card form { margin: 0; }
        .alert { display: flex; gap: 8px; text-align: left; font-size: 12.5px; padding: 10px 12px; border-radius: var(--radius-md); background: var(--destructive-soft); color: var(--destructive); margin-bottom: 20px; }
        .note { font-size: 12px; color: var(--muted-foreground); background: var(--secondary); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 12px; text-align: left; margin-bottom: 16px; }
        .note code { font-family: var(--font-mono); font-size: 11px; }
        .divider { display: flex; align-items: center; gap: 10px; color: var(--muted-foreground); font-size: 11px; margin: 18px 0; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .foot { font-size: 11px; color: var(--muted-foreground); margin-top: 22px; }
        .foot a { color: var(--primary); }
    </style>
</head>
<body class="canvas-gradient">
    <div class="card">
        <img src="{{ asset('cbox/assets/logo/cbox-logo-h100.png') }}" alt="Cbox">
        <h1>Sign in to Cbox&nbsp;·&nbsp;Billing</h1>
        <p class="sub">Authentication is handled by Cbox ID.</p>

        @if (session('error'))
            <div class="alert" role="alert">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if ($configured)
            <a class="cbx-btn cbx-btn--primary" href="{{ route('auth.redirect') }}">
                Continue with Cbox ID
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </a>
        @else
            <div class="note">
                No Cbox ID instance is configured. Set <code>CBOX_ID_ISSUER</code>, <code>CBOX_ID_CLIENT_ID</code> and <code>CBOX_ID_REDIRECT_URI</code> in <code>.env</code> to enable single sign-on.
            </div>
        @endif

        @if ($demoAllowed)
            @if ($configured)<div class="divider">or</div>@endif
            <form method="POST" action="{{ route('auth.demo') }}">
                @csrf
                <button type="submit" class="cbx-btn cbx-btn--secondary" style="width:100%;height:40px;font-size:14px">Continue in demo mode</button>
            </form>
        @endif

        <p class="foot">One account across every Cbox product.</p>
    </div>
</body>
</html>
