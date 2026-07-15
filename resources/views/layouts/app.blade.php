<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · Cbox · Billing</title>
    <link rel="icon" href="{{ asset('cbox/assets/logo/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('cbox/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('cbox/cbox-app.css') }}">
</head>
<body>
@php
    $areas = $navAreas ?? config('cbox_nav.areas');
    $activeArea = $activeArea ?? 'home';
    $activeNav = $activeNav ?? null;
    $current = $areas[$activeArea] ?? $areas['home'];
    $areaUrl = function (array $area) {
        return route($area['route']);
    };
    $navUrl = function (array $item) {
        return $item['route'] ? route($item['route'], $item['params'] ?? []) : '#';
    };
    $u = $currentUser ?? null;
    $userName = $u?->name ?? 'Account';
    $userEmail = $u?->email ?? '';
    $userInitials = $u?->initials() ?? '··';
    $orgName = $u?->org ?? 'Personal';
    $orgInitials = $u?->orgInitials() ?? 'PE';
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
                    <button type="submit" class="act" style="width:100%;color:var(--destructive)">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])Log out</button>
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
                        <div class="acct"><span>sn@cbox.dk</span><a href="#">Sign out</a></div>
                        <button class="ws cur" data-ws="Cbox Systems" data-ini="CB"><span class="wsav" style="background:var(--accent-soft);color:var(--primary)">CB</span><span class="wsmeta"><span class="wsname">Cbox Systems</span><span class="wssub">Team · DKK</span></span><kbd class="k">⌘1</kbd><span class="tick">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 2])</span></button>
                        <button class="ws" data-ws="Hverdag ApS" data-ini="HV"><span class="wsav" style="background:var(--success-soft);color:var(--success)">HV</span><span class="wsmeta"><span class="wsname">Hverdag ApS</span><span class="wssub">trial — 7 days left</span></span><kbd class="k">⌘2</kbd><span class="tick">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 2])</span></button>
                        <button class="ws" data-ws="Meridian Labs" data-ini="ML"><span class="wsav" style="background:var(--warning-soft);color:var(--warning-foreground)">ML</span><span class="wsmeta"><span class="wsname">Meridian Labs</span><span class="wssub">Business · USD</span></span><kbd class="k">⌘3</kbd><span class="tick">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 2])</span></button>
                        <div class="div"></div>
                        <button class="act">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])Create organization</button>
                    </div>
                </span>
                <span class="sep">/</span>
                <span class="muted">@yield('crumb', 'Dashboard')</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px">
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
    <input id="palinput" placeholder="Type a command or search…">
    <div class="grp">
        <p>Navigate</p>
        <a class="cmd sel" href="{{ route('billing.dashboard') }}">@include('partials.icon', ['name' => 'home', 'size' => 15, 'sw' => 1.7])Go to Dashboard<kbd class="k">G D</kbd></a>
        <a class="cmd" href="{{ route('billing.subscriptions') }}">@include('partials.icon', ['name' => 'repeat', 'size' => 15, 'sw' => 1.7])Go to Subscriptions<kbd class="k">G S</kbd></a>
        <a class="cmd" href="{{ route('billing.invoices') }}">@include('partials.icon', ['name' => 'invoice', 'size' => 15, 'sw' => 1.7])Go to Invoices<kbd class="k">G I</kbd></a>
    </div>
    <div class="grp" style="border-top:1px solid var(--border)">
        <p>Actions</p>
        <div class="cmd">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New subscription<kbd class="k">N</kbd></div>
        <div class="cmd">@include('partials.icon', ['name' => 'receipt', 'size' => 15, 'sw' => 1.7])Create invoice</div>
    </div>
</div>

<script>
    // Light/dark — same tokens, .dark scope on <html> so body's inherited color flips too.
    const htmlEl = document.documentElement;
    function setMode(dark){ htmlEl.classList.toggle('dark', dark); try{localStorage.setItem('cbox-mode', dark?'dark':'light')}catch(e){} }
    document.getElementById('modebtn').addEventListener('click', () => setMode(!htmlEl.classList.contains('dark')));
    try{ if(localStorage.getItem('cbox-mode')==='dark') setMode(true); }catch(e){}

    // ⌘K command palette
    const pal = document.getElementById('pal'), palbg = document.getElementById('palbg'), palinput = document.getElementById('palinput');
    function openPal(){ pal.classList.add('open'); palbg.classList.add('open'); palinput.value=''; palinput.focus(); }
    function closePal(){ pal.classList.remove('open'); palbg.classList.remove('open'); }
    document.getElementById('palbtn').addEventListener('click', openPal);
    palbg.addEventListener('click', closePal);
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
    const wsButtons = Array.from(orgmenu.querySelectorAll('.ws[data-ws]'));
    function switchWs(btn){ wsButtons.forEach(b => b.classList.remove('cur')); btn.classList.add('cur'); document.getElementById('orgname').textContent = btn.dataset.ws; document.getElementById('orgav').textContent = btn.dataset.ini; orgmenu.classList.remove('open'); }
    wsButtons.forEach(b => b.addEventListener('click', () => switchWs(b)));
    window.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && ['1','2','3'].includes(e.key)) { const b = wsButtons[Number(e.key)-1]; if (b) { e.preventDefault(); switchWs(b); } } });

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
@yield('scripts')
</body>
</html>
