<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not authorized · Cbox · Billing</title>
    <link rel="icon" href="{{ asset('cbox/assets/logo/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('cbox/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('cbox/cbox-app.css') }}">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { width: 100%; max-width: 420px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); box-shadow: var(--shadow-card); padding: 32px 28px; text-align: center; }
        .card img { height: 30px; margin-bottom: 22px; }
        .card .badge { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 999px; background: var(--destructive-soft); color: var(--destructive); margin-bottom: 16px; }
        .card h1 { font-family: var(--font-display); font-size: 19px; font-weight: 600; margin: 0 0 8px; letter-spacing: -0.01em; }
        .card p { font-size: 13px; color: var(--muted-foreground); margin: 0 0 8px; line-height: 1.55; }
        .card form { margin: 20px 0 0; }
        .card .cbx-btn { width: 100%; height: 40px; font-size: 14px; }
        .foot { font-size: 11px; color: var(--muted-foreground); margin-top: 22px; }
    </style>
</head>
<body class="canvas-gradient">
    <div class="card">
        <img src="{{ asset('cbox/assets/logo/cbox-logo-h100.png') }}" alt="Cbox">
        <div class="badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <h1>Not authorized for this console</h1>
        <p>Your Cbox ID sign-in succeeded, but this account is not a member of an organization permitted to operate the Cbox Billing provider console.</p>
        <p>If you believe this is a mistake, ask your administrator to add your organization to the operator allowlist.</p>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="cbx-btn cbx-btn-secondary">Sign out</button>
        </form>

        <p class="foot">Provider console · access restricted to operators</p>
    </div>
</body>
</html>
