<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Checkout') · Cbox · Billing</title>
    <link rel="icon" href="{{ asset('cbox/assets/logo/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('cbox/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('cbox/cbox-app.css') }}">
    <script>
        // Hosted pages carry no theme switcher, so they follow the viewer's OS preference —
        // mirroring the prefers-color-scheme handling the order-form and storefront already do.
        // The dark palette lives under the `.dark` scope (tokens/colors.css); toggling it on
        // <html> in <head> applies before first paint, so there is no light-mode flash.
        (function () {
            try {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                var apply = function (dark) { document.documentElement.classList.toggle('dark', dark); };
                apply(mq.matches);
                mq.addEventListener('change', function (e) { apply(e.matches); });
            } catch (e) {}
        })();
    </script>
    @stack('head')
    <style>
        body { min-height: 100vh; padding: 40px 20px; }
        .hosted { width: 100%; max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
        .hosted-brand { display: flex; align-items: center; gap: 10px; }
        .hosted-brand img { height: 24px; }
        .hosted-brand span { font-size: 12px; color: var(--muted-foreground); }
        .hosted-card { border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); box-shadow: var(--shadow-card); overflow: hidden; }
        .hosted-card > header { padding: 18px 24px; border-bottom: 1px solid var(--border); }
        .hosted-card > header h1 { font-family: var(--font-display); font-size: 17px; font-weight: 600; margin: 0; letter-spacing: -0.01em; }
        .hosted-card > header p { font-size: 12.5px; color: var(--muted-foreground); margin: 4px 0 0; }
        .hosted-body { padding: 20px 24px; }
        .hosted-foot { font-size: 11px; color: var(--muted-foreground); text-align: center; }
        .line { display: flex; align-items: baseline; justify-content: space-between; padding: 10px 0; }
        .line + .line { border-top: 1px solid var(--border); }
        .line .k { font-size: 13px; color: var(--muted-foreground); }
        .line .v { font-size: 14px; font-weight: 600; }
        .total .v { font-size: 18px; }
        .element { margin-top: 8px; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; min-height: 44px; background: var(--background); }
        .alert { display: none; gap: 8px; font-size: 12.5px; padding: 10px 12px; border-radius: var(--radius-md); background: var(--destructive-soft); color: var(--destructive); margin-top: 14px; }
        .alert.show { display: flex; }
        .note { font-size: 12px; color: var(--muted-foreground); background: var(--secondary); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 12px; margin-top: 14px; }
        .state { display: none; align-items: center; gap: 10px; font-size: 13px; color: var(--muted-foreground); margin-top: 14px; }
        .state.show { display: flex; }
        .spin { width: 15px; height: 15px; border: 2px solid var(--border); border-top-color: var(--primary); border-radius: 9999px; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .hosted .cbx-btn { width: 100%; height: 42px; font-size: 14px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 640px) { .grid2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="canvas-gradient">
    <div class="hosted">
        <div class="hosted-brand">
            <img src="{{ asset('cbox/assets/logo/cbox-logo-h100.png') }}" alt="Cbox">
            <span>Billing</span>
        </div>
        @yield('content')
        <p class="hosted-foot">Secured by Cbox · Billing. Card details are handled by the payment provider and never touch this server.</p>
    </div>
    @include('partials.confirm-dialog')
    @stack('scripts')
</body>
</html>
