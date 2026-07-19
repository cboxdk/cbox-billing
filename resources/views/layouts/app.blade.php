<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · Cbox · Billing</title>
    <link rel="icon" href="{{ asset('cbox/assets/logo/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('cbox/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('cbox/cbox-app.css') }}">
    {{-- Per-tenant console branding when a white-label plugin is installed; inert otherwise. --}}
    @consoleBrandingStyle
</head>
<body>
@php
    // Sourced from the shared console-kit nav registry via App\Http\View\NavigationComposer,
    // so an installed plugin's areas/pages render here with no edit to this layout.
    $areas = $navAreas ?? [];
    $activeArea = $activeArea ?? 'home';
    $activeNav = $activeNav ?? null;
    $current = $areas[$activeArea] ?? $areas['home'];
    $areaUrl = function (array $area) {
        return route($area['route']);
    };
    $navUrl = function (array $item) {
        if (! $item['route']) {
            return '#';
        }
        $url = route($item['route'], $item['params'] ?? []);
        // A `fragment` jumps to an on-page anchor (e.g. the Settings sections that all render
        // on one page) so the "deep link" actually scrolls there, not just highlights the nav.
        return ($item['fragment'] ?? null) ? $url.'#'.$item['fragment'] : $url;
    };
    $u = $currentUser ?? null;
    $userName = $u?->name ?? 'Account';
    $userEmail = $u?->email ?? '';
    $userInitials = $u?->initials() ?? '··';
    $orgName = $u?->orgLabel() ?? 'Personal';
    $orgInitials = $u?->orgInitials() ?? 'PE';
    // The active Cbox ID environment (plane) the operator is in. Falls back to the single
    // configured default until Cbox ID emits an `environment` claim.
    $environmentLabel = $u?->environmentLabel() ?? (string) (config('cbox-id-client.environment_default') ?: 'default');
@endphp
<div class="shell" id="root">

    {{-- TIER 1 — icon rail (Intercom model): one icon per area + account at the bottom --}}
    <aside class="cbx-rail">
        <div class="cbx-rail-hd">
            <div class="cbx-rail-brand"><img src="{{ asset('cbox/assets/logo/cbox-icon-128.png') }}" alt="Cbox"></div>
            <button class="cbx-pin set" title="Unpin (auto-hide)">@include('partials.icon', ['name' => 'pin', 'size' => 14, 'sw' => 1.7])</button>
        </div>
        @foreach ($areas as $key => $area)
            <a href="{{ $areaUrl($area) }}" @class(['cbx-on' => $key === $activeArea]) data-area="{{ $key }}">
                @include('partials.icon', ['name' => $area['icon'], 'size' => 17])
                <span class="lbl">{{ $area['label'] }}</span>
                @isset($area['badge'])<span class="badge">{{ $area['badge'] }}</span>@endisset
            </a>
        @endforeach
        <div class="cbx-rail-foot">
            <a href="#" id="acctbtn" style="height:32px" aria-haspopup="menu" aria-expanded="false">
                <span class="avatar-sm" style="width:22px;height:22px;font-size:9px;margin-left:-3px">{{ $userInitials }}</span>
                <span class="lbl">{{ $userName }}</span>
            </a>
            <div class="menu" id="acctmenu" style="position:fixed;width:260px" role="menu">
                <button class="ws cur"><span class="avatar-sm">{{ $userInitials }}</span><span class="wsmeta"><span class="wsname">{{ $userName }}</span><span class="wssub">{{ $userEmail ?: 'Signed in via Cbox ID' }}</span></span><span class="tick">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 2])</span></button>
                <div class="div"></div>
                <button class="act">Manage account <span class="num" style="margin-left:auto;font-size:10px">↗ id</span></button>
                <form method="POST" action="{{ route('logout') }}" style="margin:0">
                    @csrf
                    <button type="submit" class="act" style="width:100%;color:var(--destructive)">@include('partials.icon', ['name' => 'log-out', 'size' => 14, 'sw' => 1.7])Log out</button>
                </form>
            </div>
        </div>
    </aside>

    {{-- TIER 2 — contextual subnav for the active area --}}
    <aside class="cbx-subnav" id="subnav" style="display:flex;flex-direction:column">
        <div class="cbx-subnav-hd">
            <span>{{ $current['label'] }}</span>
            <button class="cbx-subnav-toggle" title="Collapse panel (⌘.)">@include('partials.icon', ['name' => 'panel', 'size' => 14, 'sw' => 1.7])</button>
        </div>
        <nav style="flex:1">
            @foreach ($current['nav'] as $item)
                <a href="{{ $navUrl($item) }}" @class(['cbx-on' => $item['key'] === $activeNav])>
                    {{ $item['label'] }}
                    @if (($item['count'] ?? null) !== null)<span class="cnt">{{ $item['count'] }}</span>@endif
                </a>
            @endforeach
        </nav>
        <div class="cbx-strip"><span class="vlabel">Cbox · Billing</span><button class="cbx-strip-expand" title="Expand (⌘.)">»</button></div>
    </aside>

    <div class="main">
        @if (! empty($testMode))
            {{-- Persistent, unmistakable sandbox indicator. When the console is in test mode
                 every list/detail/report below is scoped to the isolated test dataset. --}}
            <div class="cbx-testmode-strip" role="status" style="display:flex;align-items:center;justify-content:center;gap:10px;height:30px;background:var(--warning-soft);color:var(--warning);border-bottom:1px solid var(--warning);font-size:11px;font-weight:600;letter-spacing:.03em">
                <span style="display:inline-flex;align-items:center;gap:6px">
                    <span style="width:7px;height:7px;border-radius:9999px;background:var(--warning)"></span>
                    TEST MODE — sandbox data only. No real charges or emails.
                </span>
                <form method="POST" action="{{ route('billing.test-mode.toggle') }}" style="display:inline">
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <button type="submit" class="cbx-btn cbx-btn--sm cbx-btn--ghost" style="height:20px">Switch to live</button>
                </form>
            </div>
        @endif

        {{-- Topbar: org context, ⌘K, apps, notifications --}}
        <header class="tb">
            <div class="crumb">
                <span style="position:relative">
                    <button id="orgbtn" aria-haspopup="menu" aria-expanded="false">
                        <span class="avatar-sm" id="orgav" style="width:18px;height:18px;font-size:8px;border-radius:5px;background:var(--accent-soft);color:var(--primary)">{{ $orgInitials }}</span>
                        <span id="orgname">{{ $orgName }}</span>
                        @include('partials.icon', ['name' => 'chevron-down', 'size' => 12, 'sw' => 1.7])
                    </button>
                    <div class="menu" id="orgmenu" style="top:100%;left:0;margin-top:4px" role="menu">
                        <div class="acct"><span>{{ $userEmail ?: 'Signed in via Cbox ID' }}</span></div>
                        <button class="ws cur"><span class="wsav" style="background:var(--accent-soft);color:var(--primary)">{{ $orgInitials }}</span><span class="wsmeta"><span class="wsname">{{ $orgName }}</span><span class="wssub">Current organization</span></span><span class="tick">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 2])</span></button>
                    </div>
                </span>
                <span class="env-chip" data-env-chip title="Active Cbox ID environment (billing plane)" style="display:inline-flex;align-items:center;height:18px;margin-left:8px;padding:0 7px;border-radius:5px;background:var(--accent-soft);color:var(--primary);font-size:10px;font-weight:600;letter-spacing:.02em">{{ $environmentLabel }}</span>
                @hasSection('breadcrumb')
                    @yield('breadcrumb')
                @else
                    <span class="sep">/</span>
                    <span class="muted">@yield('crumb', 'Dashboard')</span>
                @endif
            </div>
            <div style="display:flex;align-items:center;gap:6px">
                @if (! empty($testMode))
                    <span class="cbx-pill cbx-pill--warning" title="The console is scoped to the test dataset"><span class="dot"></span>Test mode</span>
                @else
                    <form method="POST" action="{{ route('billing.test-mode.toggle') }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="enabled" value="1">
                        <button type="submit" class="cbx-btn cbx-btn--sm cbx-btn--ghost" title="View the isolated sandbox dataset" style="height:28px">Test mode</button>
                    </form>
                @endif
                <button class="cbx-search" id="palbtn" style="width:200px;height:28px">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<span class="label">Search or jump to…</span><kbd>⌘K</kbd></button>
                <button class="iconbtn" id="modebtn" title="Toggle light/dark">@include('partials.icon', ['name' => 'moon', 'size' => 14, 'sw' => 1.7])</button>
                <button class="iconbtn" title="Cbox apps">@include('partials.icon', ['name' => 'grid', 'size' => 14, 'sw' => 1.7])</button>
                <button class="iconbtn" title="Notifications">@include('partials.icon', ['name' => 'bell', 'size' => 16, 'sw' => 1.7])<span style="position:absolute;top:4px;right:5px;width:6px;height:6px;border-radius:9999px;background:var(--destructive);border:1.5px solid var(--background)"></span></button>
            </div>
        </header>

        <div class="content canvas-gradient">
            @yield('screen')
        </div>
    </div>
</div>

{{-- ⌘K command palette --}}
<div class="pal-bg" id="palbg"></div>
<div class="pal" id="pal" role="dialog" aria-label="Command palette">
    <input id="palinput" placeholder="Type a command or search…" aria-label="Command palette search">
    <div class="grp" id="palgroup-nav">
        <p>Navigate</p>
        <a class="cmd sel" href="{{ route('billing.dashboard') }}">@include('partials.icon', ['name' => 'home', 'size' => 15, 'sw' => 1.7])Go to Dashboard<kbd class="k">G D</kbd></a>
        <a class="cmd" href="{{ route('billing.subscriptions') }}">@include('partials.icon', ['name' => 'repeat', 'size' => 15, 'sw' => 1.7])Go to Subscriptions<kbd class="k">G S</kbd></a>
        <a class="cmd" href="{{ route('billing.invoices') }}">@include('partials.icon', ['name' => 'invoice', 'size' => 15, 'sw' => 1.7])Go to Invoices<kbd class="k">G I</kbd></a>
        <a class="cmd" href="{{ route('billing.customers') }}">@include('partials.icon', ['name' => 'building', 'size' => 15, 'sw' => 1.7])Go to Customers</a>
        <a class="cmd" href="{{ route('billing.access-grants') }}">@include('partials.icon', ['name' => 'shield', 'size' => 15, 'sw' => 1.7])Go to Access grants</a>
        <a class="cmd" href="{{ route('billing.usage') }}">@include('partials.icon', ['name' => 'activity', 'size' => 15, 'sw' => 1.7])Go to Usage</a>
        <a class="cmd" href="{{ route('billing.credit-notes') }}">@include('partials.icon', ['name' => 'invoice', 'size' => 15, 'sw' => 1.7])Go to Credit notes</a>
        <a class="cmd" href="{{ route('billing.catalog') }}">@include('partials.icon', ['name' => 'box', 'size' => 15, 'sw' => 1.7])Go to Catalog</a>
        <a class="cmd" href="{{ route('billing.products') }}">@include('partials.icon', ['name' => 'box', 'size' => 15, 'sw' => 1.7])Go to Products</a>
        <a class="cmd" href="{{ route('billing.coupons') }}">@include('partials.icon', ['name' => 'receipt', 'size' => 15, 'sw' => 1.7])Go to Coupons</a>
        <a class="cmd" href="{{ route('billing.meters') }}">@include('partials.icon', ['name' => 'gauge', 'size' => 15, 'sw' => 1.7])Go to Meters</a>
        <a class="cmd" href="{{ route('billing.settings') }}">@include('partials.icon', ['name' => 'settings', 'size' => 15, 'sw' => 1.7])Go to Settings</a>
        <a class="cmd" href="{{ route('billing.settings.webhooks') }}">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 15, 'sw' => 1.7])Go to Webhooks</a>
        <a class="cmd" href="{{ route('billing.settings.emails') }}">@include('partials.icon', ['name' => 'bell', 'size' => 15, 'sw' => 1.7])Go to Email templates</a>
        <a class="cmd" href="{{ route('openapi.docs') }}" target="_blank" rel="noopener">@include('partials.icon', ['name' => 'box', 'size' => 15, 'sw' => 1.7])Open API reference (OpenAPI)</a>
    </div>
    {{-- Only real, existing actions — no no-op commands. --}}
    <div class="grp" id="palgroup-actions" style="border-top:1px solid var(--border)">
        <p>Actions</p>
        <a class="cmd" href="{{ route('billing.subscriptions.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New subscription</a>
        <a class="cmd" href="{{ route('billing.invoices.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])Create invoice</a>
        <a class="cmd" href="{{ route('billing.products.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New product</a>
        <a class="cmd" href="{{ route('billing.coupons.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New coupon</a>
        <a class="cmd" href="{{ route('billing.plans.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New plan</a>
        <a class="cmd" href="{{ route('billing.catalog.prices.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New price</a>
        <a class="cmd" href="{{ route('billing.meters.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New meter</a>
        <a class="cmd" href="{{ route('billing.settings.tokens.create') }}">@include('partials.icon', ['name' => 'key', 'size' => 14, 'sw' => 1.7])New API token</a>
        <a class="cmd" href="{{ route('billing.settings.webhooks.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])Register webhook endpoint</a>
        <a class="cmd" href="{{ route('billing.settings.sellers.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New seller</a>
        <a class="cmd" href="{{ route('billing.subscriptions.dunning') }}">@include('partials.icon', ['name' => 'activity', 'size' => 15, 'sw' => 1.7])Review dunning</a>
    </div>
    <div class="grp" id="palgroup-empty" style="display:none;padding:14px 16px">
        <span class="mut" style="font-size:13px">No commands match.</span>
    </div>
</div>

<script>
    // Light/dark — same tokens, .dark scope on <html> so body's inherited color flips too.
    const htmlEl = document.documentElement;
    function setMode(dark){ htmlEl.classList.toggle('dark', dark); try{localStorage.setItem('cbox-mode', dark?'dark':'light')}catch(e){} }
    document.getElementById('modebtn').addEventListener('click', () => setMode(!htmlEl.classList.contains('dark')));
    try{ if(localStorage.getItem('cbox-mode')==='dark') setMode(true); }catch(e){}

    // ⌘K command palette — with live filtering over the (real) command links.
    const pal = document.getElementById('pal'), palbg = document.getElementById('palbg'), palinput = document.getElementById('palinput');
    const palCmds = Array.from(pal.querySelectorAll('.cmd'));
    const palEmpty = document.getElementById('palgroup-empty');
    const palGroups = Array.from(pal.querySelectorAll('.grp')).filter(g => g.id !== 'palgroup-empty');
    function filterPal(){
        const q = palinput.value.trim().toLowerCase();
        let visible = 0;
        palCmds.forEach(c => { const hit = c.textContent.toLowerCase().includes(q); c.style.display = hit ? '' : 'none'; if (hit) visible++; });
        // Hide a group whose commands are all filtered out.
        palGroups.forEach(g => { const any = Array.from(g.querySelectorAll('.cmd')).some(c => c.style.display !== 'none'); g.style.display = any ? '' : 'none'; });
        palEmpty.style.display = visible === 0 ? '' : 'none';
        palCmds.forEach(c => c.classList.remove('sel'));
        const first = palCmds.find(c => c.style.display !== 'none'); if (first) first.classList.add('sel');
    }
    function openPal(){ pal.classList.add('open'); palbg.classList.add('open'); palinput.value=''; filterPal(); palinput.focus(); }
    function closePal(){ pal.classList.remove('open'); palbg.classList.remove('open'); }
    document.getElementById('palbtn').addEventListener('click', openPal);
    palbg.addEventListener('click', closePal);
    palinput.addEventListener('input', filterPal);
    palinput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { const sel = palCmds.find(c => c.classList.contains('sel') && c.style.display !== 'none'); if (sel && sel.href) { e.preventDefault(); window.location = sel.href; } } });
    window.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.classList.contains('open') ? closePal() : openPal(); }
        if (e.key === 'Escape') closePal();
    });

    // Org + account switchers (Notion/Slack pattern)
    const orgbtn = document.getElementById('orgbtn'), orgmenu = document.getElementById('orgmenu');
    const acctbtn = document.getElementById('acctbtn'), acctmenu = document.getElementById('acctmenu');
    function toggleMenu(btn, menu){ const open = !menu.classList.contains('open'); [orgmenu, acctmenu].forEach(m => m.classList.remove('open')); menu.classList.toggle('open', open); btn.setAttribute('aria-expanded', open); }
    orgbtn.addEventListener('click', (e) => { e.stopPropagation(); toggleMenu(orgbtn, orgmenu); });
    acctbtn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); const r = acctbtn.getBoundingClientRect(); acctmenu.style.left = Math.max(8, r.left)+'px'; acctmenu.style.bottom = (window.innerHeight - r.top + 8)+'px'; toggleMenu(acctbtn, acctmenu); });
    window.addEventListener('pointerdown', (e) => {
        if (!orgmenu.contains(e.target) && !orgbtn.contains(e.target)) orgmenu.classList.remove('open');
        if (!acctmenu.contains(e.target) && !acctbtn.contains(e.target)) { acctmenu.classList.remove('open'); const rail = document.querySelector('.cbx-rail'); if (rail && rail.classList.contains('unpinned') && !rail.contains(e.target)) rail.classList.remove('open'); }
    });
    // NOTE: multi-org switching is not part of the console yet (the org comes from the
    // Cbox ID session), so the menu shows the current org for context only — there are no
    // dead switcher buttons or no-op ⌘1/2/3 shortcuts wired here.

    // "F" focuses the list filter input (matches the kbd hint on the filter bar).
    window.addEventListener('keydown', function(e){
        if (e.key !== 'f' && e.key !== 'F') return;
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        var a = document.activeElement;
        if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable)) return;
        var input = document.querySelector('.filters input[name="q"]');
        if (input) { e.preventDefault(); input.focus(); input.select(); }
    });

    // Accessible table-row navigation — the reusable pattern for `<tr data-href>`.
    // Rows become keyboard-operable links (Enter/Space) without swallowing clicks on
    // inner controls (links, buttons, forms, inputs), which keep their own behaviour.
    (function(){
        function fromInteractive(t){ return t.closest('a, button, form, input, select, textarea, label, [data-confirm]'); }
        document.addEventListener('click', function(e){
            var row = e.target.closest && e.target.closest('tr[data-href]');
            if (!row) return;
            if (fromInteractive(e.target)) return;
            if (e.metaKey || e.ctrlKey) { window.open(row.dataset.href, '_blank'); return; }
            window.location = row.dataset.href;
        });
        document.addEventListener('keydown', function(e){
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var row = e.target.closest && e.target.closest('tr[data-href]');
            if (!row || row !== e.target) return; // only when the row itself is focused
            e.preventDefault();
            window.location = row.dataset.href;
        });
    })();

    // Button loading state — disable a form's submit control and show a spinner on submit,
    // so a slow POST can't be double-submitted. Skips forms mid-confirm (the confirm guard
    // re-submits after the operator confirms, at which point this fires cleanly).
    (function(){
        document.addEventListener('submit', function(e){
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.hasAttribute('data-confirm') && form.dataset.cbxConfirmed !== '1') return; // wait for confirm
            var btn = e.submitter || form.querySelector('button[type="submit"], button:not([type])');
            if (!btn || btn.dataset.loading === '1') return;
            btn.dataset.loading = '1';
            btn.disabled = true;
            if (!btn.querySelector('.cbx-spin')) { var s = document.createElement('span'); s.className = 'cbx-spin'; btn.insertBefore(s, btn.firstChild); }
        });
    })();

    // Subnav collapse — ⌘. everywhere
    (function(){
        var sn = document.querySelector('.cbx-subnav'); if (!sn) return;
        function t(){ sn.classList.toggle('collapsed'); }
        var b = sn.querySelector('.cbx-subnav-toggle'); if (b) b.addEventListener('click', function(e){ e.stopPropagation(); t(); });
        sn.addEventListener('click', function(e){ if (sn.classList.contains('collapsed')) { e.preventDefault(); t(); } });
        window.addEventListener('keydown', function(e){ if ((e.metaKey || e.ctrlKey) && e.key === '.') { e.preventDefault(); t(); } });
    })();

    // Main nav 3 states (Intercom): minimized rail / hover-overlay / pinned
    (function(){
        var rail = document.querySelector('.cbx-rail'); if (!rail) return;
        var pin = rail.querySelector('.cbx-pin');
        var spacer = document.createElement('div'); spacer.className = 'cbx-rail-spacer'; spacer.style.display = 'none';
        rail.parentNode.insertBefore(spacer, rail);
        var pinned = true;
        function setPinned(p){ pinned = p; rail.classList.toggle('unpinned', !p); rail.classList.toggle('open', p); spacer.style.display = p ? 'none' : ''; if (pin) { pin.classList.toggle('set', p); pin.title = p ? 'Unpin (auto-hide)' : 'Pin'; } try{localStorage.setItem('cbox-rail-pin', p?'1':'0')}catch(e){} }
        if (pin) pin.addEventListener('click', function(e){ e.stopPropagation(); setPinned(!pinned); });
        rail.addEventListener('mouseenter', function(){ if (!pinned) rail.classList.add('open'); });
        rail.addEventListener('mouseleave', function(){ var m = document.getElementById('acctmenu'); if (!pinned && !(m && m.classList.contains('open'))) rail.classList.remove('open'); });
        try{ setPinned(localStorage.getItem('cbox-rail-pin') !== '0'); }catch(e){ setPinned(true); }
    })();
</script>
@include('partials.confirm-dialog')
@yield('scripts')
</body>
</html>
