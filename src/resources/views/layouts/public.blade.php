<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="@yield('robots', 'index,follow')">
    <meta name="theme-color" content="#e0f2fe">
    <title>@yield('title', 'Book an appointment') | {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('backend/vendor/fontawesome-free/css/all.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap">
    <style>
        /* Liquid-glass theme matching metheme (blur 16px, saturate 160%, white translucent borders, 16px radius). */
        :root {
            --abs-accent: #0ea5e9; --abs-accent-2: #6366f1; --abs-ink: #0f172a; --abs-muted: #64748b;
            --glass-bg: rgba(255, 255, 255, .55); --glass-strong: rgba(255, 255, 255, .72); --glass-border: rgba(255, 255, 255, .7);
            --glass-shadow: 0 8px 32px rgba(15, 45, 74, .14), inset 0 1px 0 rgba(255, 255, 255, .8);
            --glass-blur: blur(16px) saturate(160%); --radius: 16px;
        }
        html, body { min-height: 100%; }
        body { font-family: 'Source Sans 3', 'Hind Siliguri', system-ui, sans-serif; color: var(--abs-ink);
            background: linear-gradient(135deg, #e0f2fe 0%, #ede9fe 45%, #fce7f3 100%) fixed; position: relative; overflow-x: hidden; }
        .abs-blobs { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
        .abs-blobs span { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .55; animation: abs-float 18s ease-in-out infinite alternate; }
        .abs-blobs span:nth-child(1) { width: 420px; height: 420px; background: #38bdf8; top: -120px; left: -80px; }
        .abs-blobs span:nth-child(2) { width: 360px; height: 360px; background: #a78bfa; top: 30%; right: -120px; animation-duration: 22s; }
        .abs-blobs span:nth-child(3) { width: 320px; height: 320px; background: #f472b6; bottom: -120px; left: 25%; animation-duration: 26s; }
        @keyframes abs-float { from { transform: translate(0, 0) scale(1); } to { transform: translate(60px, 40px) scale(1.12); } }
        @media (prefers-reduced-motion: reduce) { .abs-blobs span { animation: none; } }

        .glass, .abs-card { background: var(--glass-bg); backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border); border-radius: var(--radius); box-shadow: var(--glass-shadow); }
        .glass-strong { background: var(--glass-strong); }
        .abs-nav { position: sticky; top: .75rem; z-index: 1030; margin: .75rem auto 1.5rem; max-width: 1100px; padding: .55rem .9rem; border-radius: 999px; }
        .abs-nav .brand { font-weight: 700; color: var(--abs-ink); text-decoration: none; display: flex; align-items: center; gap: .5rem; }
        .abs-nav .brand .logo { width: 2rem; height: 2rem; border-radius: 50%; display: grid; place-items: center; color: #fff;
            background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); box-shadow: 0 4px 12px rgba(14, 165, 233, .35); }
        .abs-nav .nav-link { color: var(--abs-ink); border-radius: 999px; padding: .35rem .8rem; font-weight: 500; font-size: .92rem; }
        .abs-nav .nav-link:hover, .abs-nav .nav-link.active { background: rgba(255, 255, 255, .7); }

        .btn { border-radius: 12px; font-weight: 600; }
        .btn-primary { background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); border: 0; box-shadow: 0 6px 18px rgba(99, 102, 241, .28); }
        .btn-primary:hover, .btn-primary:focus { filter: brightness(1.06); background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); }
        .btn-glass { background: rgba(255, 255, 255, .6); border: 1px solid var(--glass-border); color: var(--abs-ink); }
        .btn-glass:hover { background: rgba(255, 255, 255, .85); }
        .btn-outline-primary { border-color: rgba(99, 102, 241, .45); color: #4f46e5; background: rgba(255, 255, 255, .5); }
        .btn-outline-primary:hover, .btn-check:checked + .btn-outline-primary { background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); border-color: transparent; color: #fff; }
        .btn-outline-danger { background: rgba(255, 255, 255, .5); }
        .form-control, .form-select { background-color: rgba(255, 255, 255, .65); border: 1px solid rgba(255, 255, 255, .9); border-radius: 12px; }
        .form-control:focus, .form-select:focus { background-color: #fff; border-color: #a5b4fc; box-shadow: 0 0 0 .2rem rgba(99, 102, 241, .15); }
        .text-muted { color: var(--abs-muted) !important; }
        .alert { border: 1px solid var(--glass-border); border-radius: 14px; backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur); }
        .alert-success { background: rgba(220, 252, 231, .75); } .alert-danger { background: rgba(254, 226, 226, .8); }
        .alert-info { background: rgba(224, 242, 254, .75); } .alert-warning { background: rgba(254, 243, 199, .8); }
        .list-group-item { background: rgba(255, 255, 255, .45); border-color: rgba(255, 255, 255, .7); }
        .table { --bs-table-bg: transparent; }

        /* Booking wizard pieces */
        .abs-choice { cursor: pointer; transition: transform .15s, box-shadow .15s, background .15s; border: 1px solid var(--glass-border); border-radius: 14px;
            background: rgba(255, 255, 255, .5); text-align: left; width: 100%; color: var(--abs-ink); }
        .abs-choice:hover, .abs-choice:focus-visible { transform: translateY(-2px); background: rgba(255, 255, 255, .8); box-shadow: 0 8px 20px rgba(15, 45, 74, .1); outline: none; }
        .abs-choice.active { background: #fff; box-shadow: 0 0 0 2px #818cf8, 0 8px 20px rgba(99, 102, 241, .18); }
        .abs-steps { font-size: .8rem; }
        .abs-steps .step { color: #94a3b8; }
        .abs-steps .step.done { color: #10b981; font-weight: 600; }
        .abs-steps .step.current { color: #4f46e5; font-weight: 700; }
        .abs-day { min-width: 84px; border-radius: 14px; }
        .abs-day[disabled] { opacity: .35; }
        .abs-badge-soft { border-radius: 999px; padding: .3rem .7rem; font-weight: 600; font-size: .75rem; }
        /* Wizard v2 */
        .abs-h { font-size: 1.1rem; font-weight: 700; margin-bottom: .9rem; }
        .abs-progress { gap: .25rem; counter-reset: s; }
        .abs-progress li { flex: 1; position: relative; }
        .abs-progress li:not(:last-child)::after { content: ""; position: absolute; top: 15px; left: calc(50% + 18px); right: calc(-50% + 18px); height: 2px; background: rgba(148, 163, 184, .35); border-radius: 2px; }
        .abs-progress li.done:not(:last-child)::after { background: linear-gradient(90deg, #10b981, #34d399); }
        .abs-progress button { width: 100%; border: 0; background: none; display: flex; flex-direction: column; align-items: center; gap: .3rem; padding: 0; color: #94a3b8; }
        .abs-progress .num { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-size: .8rem; font-weight: 700;
            background: rgba(255, 255, 255, .7); border: 1px solid #e2e8f0; }
        .abs-progress .lbl { font-size: .72rem; font-weight: 600; }
        .abs-progress li.done .num { background: #10b981; border-color: #10b981; color: #fff; }
        .abs-progress li.done button { color: #059669; cursor: pointer; }
        .abs-progress li.current .num { background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); border: 0; color: #fff; box-shadow: 0 4px 12px rgba(99, 102, 241, .35); }
        .abs-progress li.current button { color: #4f46e5; }

        .abs-tile { display: flex; align-items: center; gap: .75rem; padding: .75rem .85rem; height: 100%; }
        .abs-tile .body { display: flex; flex-direction: column; min-width: 0; }
        .abs-tile .t { font-weight: 600; font-size: .92rem; line-height: 1.25; }
        .abs-tile .s { font-size: .76rem; color: var(--abs-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .abs-svc { display: grid; grid-template-columns: auto 1fr; grid-template-rows: auto 1fr auto; column-gap: .7rem; row-gap: .15rem; padding: .7rem .8rem; height: 100%; }
        .abs-svc .ico { grid-row: 1 / span 3; align-self: start; }
        .abs-svc .t { font-weight: 600; font-size: .9rem; line-height: 1.25; }
        .abs-svc .d { font-size: .74rem; color: var(--abs-muted); display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
        .abs-svc .meta { grid-column: 2; display: flex; justify-content: space-between; align-items: center; padding-top: .25rem; font-size: .78rem; color: var(--abs-muted); }
        .abs-svc .d { grid-column: 2; }
        .abs-svc .meta b { color: var(--abs-ink); font-size: .85rem; }
        .ico, .avatar { flex: none; width: 2.3rem; height: 2.3rem; border-radius: 12px; display: grid; place-items: center; font-size: .95rem;
            color: var(--c); background: color-mix(in srgb, var(--c) 14%, white); }
        .avatar { border-radius: 50%; font-weight: 700; font-size: .8rem; color: #fff; background: linear-gradient(135deg, var(--c), color-mix(in srgb, var(--c) 60%, #6366f1)); }
        .abs-choice.active .ico { background: var(--c); color: #fff; }

        .abs-search { position: relative; min-width: 200px; }
        .abs-search i { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); font-size: .75rem; color: #94a3b8; }
        .abs-search input { padding-left: 1.9rem; border-radius: 999px; }
        .abs-pills, .abs-days { scrollbar-width: none; }
        .abs-pills::-webkit-scrollbar, .abs-days::-webkit-scrollbar { display: none; }
        .abs-pill { flex: none; border: 1px solid var(--glass-border); background: rgba(255, 255, 255, .55); border-radius: 999px; padding: .3rem .8rem;
            font-size: .8rem; font-weight: 600; color: #334155; display: inline-flex; align-items: center; gap: .4rem; }
        .abs-pill span { font-size: .68rem; background: rgba(148, 163, 184, .2); border-radius: 999px; padding: 0 .4rem; }
        .abs-pill:hover { background: #fff; }
        .abs-pill.active { background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); color: #fff; border-color: transparent; }
        .abs-pill.active span { background: rgba(255, 255, 255, .25); }

        .abs-day-chip { flex: none; width: 64px; padding: .45rem 0; border-radius: 14px; border: 1px solid var(--glass-border); background: rgba(255, 255, 255, .6);
            display: flex; flex-direction: column; align-items: center; line-height: 1.15; color: var(--abs-ink); }
        .abs-day-chip .wd { font-size: .68rem; text-transform: uppercase; color: var(--abs-muted); font-weight: 600; }
        .abs-day-chip .dn { font-size: 1.15rem; font-weight: 700; }
        .abs-day-chip .mo { font-size: .66rem; color: var(--abs-muted); }
        .abs-day-chip:hover:not([disabled]) { background: #fff; }
        .abs-day-chip[disabled] { opacity: .35; }
        .abs-day-chip.active { background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); color: #fff; border-color: transparent; box-shadow: 0 6px 16px rgba(99, 102, 241, .3); }
        .abs-day-chip.active .wd, .abs-day-chip.active .mo { color: rgba(255, 255, 255, .85); }
        .abs-times { display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: .4rem; }
        .abs-time { border: 1px solid var(--glass-border); background: rgba(255, 255, 255, .6); border-radius: 10px; padding: .4rem 0; font-size: .82rem; font-weight: 600; color: #334155; }
        .abs-time:hover { background: #fff; border-color: #c7d2fe; }
        .abs-time.active { background: linear-gradient(135deg, var(--abs-accent), var(--abs-accent-2)); color: #fff; border-color: transparent; }

        .abs-review { border-radius: 14px; background: rgba(255, 255, 255, .5); border: 1px solid var(--glass-border); padding: .4rem .9rem; }
        .abs-review > div { display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; font-size: .88rem; border-bottom: 1px dashed rgba(148, 163, 184, .35); }
        .abs-review > div:last-child { border-bottom: 0; }
        .abs-review span { color: var(--abs-muted); }
        .abs-review b { text-align: right; font-weight: 600; word-break: break-word; }
        .abs-review .total b { font-size: 1.05rem; }
        .abs-summary { position: sticky; top: 5.5rem; }
        .abs-summary .row-item { display: flex; gap: .75rem; align-items: flex-start; padding: .5rem 0; }
        .abs-summary .row-item i { width: 1.9rem; height: 1.9rem; border-radius: 10px; display: grid; place-items: center; background: rgba(99, 102, 241, .1); color: #6366f1; font-size: .8rem; flex: none; }
        .abs-summary .row-item.empty i { background: rgba(148, 163, 184, .15); color: #94a3b8; }
        .abs-summary .lbl { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--abs-muted); font-weight: 600; }
        .abs-summary .val { font-size: .88rem; font-weight: 600; }
        .abs-summary .row-item.empty .val { color: #94a3b8; font-weight: 500; }
        @media (max-width: 991.98px) { .abs-summary { position: static; } }
        .abs-back { display: inline-flex; align-items: center; gap: .45rem; border: 1px solid var(--glass-border); background: rgba(255, 255, 255, .6);
            border-radius: 999px; padding: .35rem .9rem .35rem .4rem; font-size: .85rem; font-weight: 600; color: #334155; transition: background .15s, transform .15s; }
        .abs-back .arr { width: 1.6rem; height: 1.6rem; border-radius: 50%; display: grid; place-items: center; background: #fff; box-shadow: 0 2px 6px rgba(15, 23, 42, .08); font-size: .7rem; }
        .abs-back:hover { background: #fff; transform: translateX(-2px); }
        .abs-pay { display: flex; gap: .75rem; align-items: center; padding: .8rem .9rem; border-radius: 14px; border: 1px solid var(--glass-border); background: rgba(255, 255, 255, .55); cursor: pointer; }
        .abs-pay input { margin: 0; }
        .abs-pay.active { background: #fff; box-shadow: 0 0 0 2px #818cf8; }
        .abs-pay.disabled { opacity: .55; cursor: not-allowed; }
        .abs-gw { display: inline-flex; align-items: center; gap: .5rem; padding: .5rem .9rem; border-radius: 12px; cursor: pointer; font-weight: 700; font-size: .88rem;
            border: 1px solid var(--glass-border); background: rgba(255, 255, 255, .6); color: var(--gw); }
        .abs-gw .dot { width: .7rem; height: .7rem; border-radius: 50%; border: 2px solid var(--gw); }
        .abs-gw.active { background: #fff; box-shadow: 0 0 0 2px var(--gw); }
        .abs-gw.active .dot { background: var(--gw); }
        .abs-gw-btn { display: flex; align-items: center; justify-content: center; gap: .5rem; width: 100%; border: 0; border-radius: 12px; padding: .65rem;
            font-weight: 700; color: #fff; background: var(--gw); box-shadow: 0 6px 16px color-mix(in srgb, var(--gw) 35%, transparent); }
        .abs-gw-btn:hover { filter: brightness(1.07); }
        footer.abs-footer { color: var(--abs-muted); font-size: .8rem; }
    </style>
    @stack('css')
</head>
<body>
    <div class="abs-blobs" aria-hidden="true"><span></span><span></span><span></span></div>

    @php $account = config('abs.account_path', 'account'); @endphp
    <nav class="abs-nav glass d-flex align-items-center justify-content-between gap-2 mx-2 mx-lg-auto">
        <a class="brand" href="{{ route('abs.public.directory') }}"><span class="logo"><i class="fas fa-calendar-check"></i></span><span class="d-none d-sm-inline">{{ config('app.name') }}</span></a>
        <div class="d-flex align-items-center gap-1">
            <a href="{{ route('abs.public.directory') }}" class="nav-link {{ request()->routeIs('abs.public.directory', 'abs.public.show') ? 'active' : '' }}"><i class="fas fa-plus-circle me-1"></i><span class="d-none d-sm-inline">Book</span></a>
            <a href="{{ route('abs.public.find') }}" class="nav-link {{ request()->routeIs('abs.public.find') ? 'active' : '' }}" title="Find my booking"><i class="fas fa-search me-1"></i><span class="d-none d-md-inline">Find booking</span></a>
            @auth
                <a href="{{ route('abs.account.dashboard') }}" class="nav-link {{ request()->routeIs('abs.account.dashboard') ? 'active' : '' }}"><i class="fas fa-calendar-alt me-1"></i><span class="d-none d-sm-inline">My bookings</span></a>
                <a href="{{ route('abs.account.profile') }}" class="nav-link {{ request()->routeIs('abs.account.profile') ? 'active' : '' }}" title="Profile"><i class="fas fa-user-circle"></i><span class="d-none d-md-inline ms-1">{{ \Illuminate\Support\Str::limit(auth()->user()->name, 14) }}</span></a>
                <form method="POST" action="{{ route('abs.account.logout') }}" class="m-0">@csrf
                    <button class="nav-link border-0 bg-transparent" title="Sign out"><i class="fas fa-sign-out-alt"></i></button>
                </form>
            @else
                <a href="{{ route('abs.account.login') }}" class="nav-link {{ request()->routeIs('abs.account.login') ? 'active' : '' }}">Sign in</a>
                <a href="{{ route('abs.account.register') }}" class="btn btn-primary btn-sm rounded-pill px-3">Create account</a>
            @endauth
        </div>
    </nav>

    <main class="container pb-5" style="max-width: @yield('width', '960px')">
        @if(session('success'))<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i>{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>{{ session('error') }}</div>@endif
        @yield('content')
    </main>
    <footer class="abs-footer text-center pb-4">© {{ date('Y') }} {{ config('app.name') }}</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    @stack('js')
</body>
</html>
