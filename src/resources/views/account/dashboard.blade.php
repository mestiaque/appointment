@extends('abs::layouts.public')

@section('title', 'My bookings')
@section('robots', 'noindex,nofollow')
@section('width', '1140px')

@php
    // A confirmed visit whose time has passed is shown as "Past" rather than "Confirmed".
    $statusOf = function ($a) {
        if ($a->status->value === 'confirmed' && $a->ends_at->isPast()) {
            return ['Past visit', 'secondary'];
        }
        return [$a->status->label(), $a->status->color()];
    };
    $initials = fn (?string $name) => collect(preg_split('/\s+/', trim(preg_replace('/^(dr|mr|mrs|ms)\.?\s+/i', '', (string) $name))))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $hour = now($next?->timezone ?? config('abs.default_timezone', 'UTC'))->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $firstName = \Illuminate\Support\Str::of($user->name)->explode(' ')->first();
@endphp

@push('css')
<style>
    .abs-hero { position: relative; overflow: hidden; border-radius: 24px; padding: 1.6rem; color: #fff;
        background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 55%, #a855f7 100%); box-shadow: 0 18px 40px rgba(79, 70, 229, .28); }
    .abs-hero::before, .abs-hero::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255, 255, 255, .14); }
    .abs-hero::before { width: 320px; height: 320px; right: -90px; top: -140px; }
    .abs-hero::after { width: 200px; height: 200px; left: 38%; bottom: -120px; }
    .abs-hero > * { position: relative; z-index: 1; }
    .abs-hero .hello { opacity: .85; font-size: .95rem; }
    .abs-hero .chip { display: inline-flex; align-items: center; gap: .4rem; background: rgba(255, 255, 255, .18); border: 1px solid rgba(255, 255, 255, .3);
        border-radius: 999px; padding: .3rem .75rem; font-size: .8rem; font-weight: 600; backdrop-filter: blur(6px); }
    .abs-next { background: rgba(255, 255, 255, .16); border: 1px solid rgba(255, 255, 255, .35); border-radius: 18px; padding: 1rem; backdrop-filter: blur(10px); }
    .abs-next .date { width: 4.6rem; flex: none; border-radius: 16px; background: #fff; color: #4f46e5; text-align: center; padding: .45rem 0; box-shadow: 0 8px 20px rgba(0, 0, 0, .12); }
    .abs-next .date .m { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #0ea5e9; }
    .abs-next .date .d { font-size: 1.9rem; font-weight: 800; line-height: 1; }
    .abs-next .date .w { font-size: .72rem; color: #64748b; }
    .abs-doc-avatar { width: 2.2rem; height: 2.2rem; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: .78rem;
        background: rgba(255, 255, 255, .9); color: #4f46e5; flex: none; }
    .btn-hero { background: #fff; color: #4338ca; border: 0; font-weight: 700; border-radius: 12px; }
    .btn-hero:hover { background: #eef2ff; color: #3730a3; }
    .btn-hero-ghost { background: rgba(255, 255, 255, .16); color: #fff; border: 1px solid rgba(255, 255, 255, .45); font-weight: 600; border-radius: 12px; }
    .btn-hero-ghost:hover { background: rgba(255, 255, 255, .28); color: #fff; }

    .abs-action { display: flex; align-items: center; gap: .75rem; padding: .85rem 1rem; border-radius: 16px; text-decoration: none; color: var(--abs-ink); height: 100%;
        background: var(--glass-bg); border: 1px solid var(--glass-border); box-shadow: var(--glass-shadow); backdrop-filter: var(--glass-blur); transition: transform .15s; }
    .abs-action:hover { transform: translateY(-3px); color: var(--abs-ink); }
    .abs-action .i { width: 2.5rem; height: 2.5rem; border-radius: 12px; display: grid; place-items: center; color: #fff; background: var(--c); flex: none;
        box-shadow: 0 6px 14px color-mix(in srgb, var(--c) 35%, transparent); }
    .abs-action .t { font-weight: 700; font-size: .9rem; line-height: 1.2; }
    .abs-action .s { font-size: .74rem; color: var(--abs-muted); }

    .abs-sec { font-size: .74rem; font-weight: 700; color: var(--abs-muted); text-transform: uppercase; letter-spacing: .08em; margin: 0 0 .6rem .2rem; }
    .abs-timeline { position: relative; padding-left: 1.3rem; }
    .abs-timeline::before { content: ""; position: absolute; left: .42rem; top: .4rem; bottom: .4rem; width: 2px; background: linear-gradient(#c7d2fe, #fbcfe8); border-radius: 2px; }
    .abs-tl { position: relative; display: flex; gap: .8rem; align-items: center; padding: .7rem .9rem; margin-bottom: .55rem; border-radius: 14px;
        background: var(--glass-bg); border: 1px solid var(--glass-border); text-decoration: none; color: var(--abs-ink); transition: background .15s; }
    .abs-tl:hover { background: rgba(255, 255, 255, .85); color: var(--abs-ink); }
    .abs-tl::before { content: ""; position: absolute; left: -1.13rem; top: 50%; width: .7rem; height: .7rem; margin-top: -.35rem; border-radius: 50%;
        background: #fff; border: 2px solid var(--dot, #818cf8); }
    .abs-tl .when { width: 3.6rem; flex: none; text-align: center; line-height: 1.1; }
    .abs-tl .when .d { font-weight: 800; font-size: 1.1rem; }
    .abs-tl .when .m { font-size: .68rem; color: var(--abs-muted); text-transform: uppercase; }
    .abs-tl.past { opacity: .85; }
    .abs-side .item { display: flex; align-items: center; gap: .7rem; padding: .55rem .2rem; text-decoration: none; color: var(--abs-ink); border-bottom: 1px dashed rgba(148, 163, 184, .35); }
    .abs-side .item:last-child { border-bottom: 0; }
    .abs-side .item:hover .tt { color: #4f46e5; }
    .abs-side .tt { font-weight: 600; font-size: .86rem; line-height: 1.25; }
    .abs-side .ss { font-size: .74rem; color: var(--abs-muted); }
</style>
@endpush

@section('content')
{{-- Hero: greeting, stats, next appointment --}}
<div class="abs-hero mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <div class="hello">{{ $greeting }},</div>
            <h1 class="h3 fw-bold mb-2">{{ $firstName }} 👋</h1>
            <div class="d-flex flex-wrap gap-2">
                <span class="chip"><i class="fas fa-calendar-day"></i>{{ $stats['upcoming'] }} upcoming</span>
                <span class="chip"><i class="fas fa-check-double"></i>{{ $stats['visits'] }} completed</span>
                <span class="chip"><i class="fas fa-store"></i>{{ $stats['businesses'] }} {{ \Illuminate\Support\Str::plural('place', $stats['businesses']) }}</span>
            </div>
        </div>
        <a href="{{ route('abs.public.directory') }}" class="btn btn-hero px-3"><i class="fas fa-plus me-1"></i>New booking</a>
    </div>

    @if($next)
        @php $item = $next->items->first(); $start = $next->localStartsAt(); [$label] = $statusOf($next); @endphp
        <div class="abs-next">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <div class="d-flex gap-3 align-items-center flex-grow-1" style="min-width: 260px">
                <div class="date"><div class="m">{{ $start->format('M') }}</div><div class="d">{{ $start->format('d') }}</div><div class="w">{{ $start->format('D') }}</div></div>
                <div class="flex-grow-1" style="min-width: 0">
                    <div class="small text-uppercase fw-bold" style="letter-spacing:.06em;opacity:.8">Next appointment · {{ $label }}</div>
                    <div class="h5 fw-bold mb-1">{{ $item?->service_name }}</div>
                    <div class="d-flex flex-wrap gap-3 small" style="opacity:.95">
                        <span><i class="far fa-clock me-1"></i>{{ $start->format('h:i A') }} · {{ $item?->duration_minutes }} min</span>
                        @if($next->branch)<span><i class="fas fa-map-marker-alt me-1"></i>{{ $next->branch->name }}</span>@endif
                        <span><i class="fas fa-hourglass-half me-1"></i><span id="abs-countdown" data-start="{{ $next->starts_at->toIso8601String() }}"></span></span>
                    </div>
                </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="abs-doc-avatar">{{ $initials($next->staff?->name) }}</span>
                    <div class="small lh-sm"><div class="fw-bold">{{ $next->staff?->name }}</div><div style="opacity:.85">{{ $next->business?->name }}</div></div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="{{ $next->manageUrl() }}" class="btn btn-hero btn-sm px-3"><i class="fas fa-cog me-1"></i>Manage / reschedule</a>
                <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('abs.public.print', now()->addHours(2), ['reference' => $next->booking_reference]) }}" target="_blank" rel="noopener" class="btn btn-hero-ghost btn-sm px-3"><i class="fas fa-print me-1"></i>Print slip</a>
                @if($next->outstandingMinor() > 0)
                    <a href="{{ $next->manageUrl() }}#pay" class="btn btn-hero-ghost btn-sm px-3"><i class="fas fa-wallet me-1"></i>{{ $next->money($next->outstandingMinor()) }} due</a>
                @endif
                <span class="ms-auto small align-self-center font-monospace" style="opacity:.85">{{ $next->booking_reference }}</span>
            </div>
        </div>
    @else
        <div class="abs-next d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div><div class="fw-bold">No upcoming appointments</div><div class="small" style="opacity:.85">Book a visit in less than a minute.</div></div>
            <a href="{{ route('abs.public.directory') }}" class="btn btn-hero px-3">Book now</a>
        </div>
    @endif
</div>

{{-- Quick actions --}}
<div class="row g-2 g-md-3 mb-4">
    @foreach([
        [route('abs.public.directory'), 'fa-calendar-plus', '#0ea5e9', 'Book', 'New appointment'],
        [route('abs.public.find'), 'fa-search', '#8b5cf6', 'Find booking', 'By booking number'],
        ['#abs-docs', 'fa-file-medical', '#ec4899', 'Documents', $documents->count().' '.\Illuminate\Support\Str::plural('file', $documents->count())],
        [route('abs.account.profile'), 'fa-user-cog', '#10b981', 'Profile', 'Details & password'],
    ] as [$href, $icon, $color, $title, $sub])
        <div class="col-6 col-md-3">
            <a href="{{ $href }}" class="abs-action" style="--c: {{ $color }}">
                <span class="i"><i class="fas {{ $icon }}"></i></span>
                <span><span class="t d-block">{{ $title }}</span><span class="s">{{ $sub }}</span></span>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-4">
    <div class="col-lg-7">
        @if($upcoming->isNotEmpty())
            <h2 class="abs-sec">Also coming up</h2>
            <div class="abs-timeline mb-4">
                @foreach($upcoming as $a)
                    @php [$label, $color] = $statusOf($a); @endphp
                    <a href="{{ $a->manageUrl() }}" class="abs-tl" style="--dot: #6366f1">
                        <div class="when"><div class="d">{{ $a->localStartsAt()->format('d') }}</div><div class="m">{{ $a->localStartsAt()->format('M') }}</div></div>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="fw-semibold text-truncate">{{ $a->items->first()?->service_name }}</div>
                            <div class="small text-muted text-truncate">{{ $a->localStartsAt()->format('D, h:i A') }} · {{ $a->staff?->name }}</div>
                        </div>
                        <span class="badge abs-badge-soft bg-{{ $color }}-subtle text-{{ $color }}">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <h2 class="abs-sec">History</h2>
        @if($history->isEmpty())
            <div class="abs-card p-4 text-center text-muted small"><i class="fas fa-history fa-lg d-block mb-2"></i>Your past visits will appear here.</div>
        @else
            <div class="abs-timeline">
                @foreach($history as $a)
                    @php [$label, $color] = $statusOf($a); @endphp
                    <a href="{{ $a->manageUrl() }}" class="abs-tl past" style="--dot: {{ ['success' => '#10b981', 'danger' => '#ef4444', 'secondary' => '#94a3b8'][$color] ?? '#818cf8' }}">
                        <div class="when"><div class="d">{{ $a->localStartsAt()->format('d') }}</div><div class="m">{{ $a->localStartsAt()->format('M y') }}</div></div>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="fw-semibold text-truncate">{{ $a->items->first()?->service_name }}</div>
                            <div class="small text-muted text-truncate">{{ $a->staff?->name }} · {{ $a->business?->name }}</div>
                        </div>
                        <span class="badge abs-badge-soft bg-{{ $color }}-subtle text-{{ $color }}">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
            <div class="mt-2">{{ $history->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>

    <div class="col-lg-5 abs-side">
        @if($due->isNotEmpty())
            <div class="abs-card p-3 p-md-4 mb-3" style="border-left: 4px solid #f59e0b">
                <h2 class="h6 fw-bold mb-2"><i class="fas fa-wallet me-1 text-warning"></i> Payment due</h2>
                @foreach($due as $a)
                    <a href="{{ $a->manageUrl() }}#pay" class="item">
                        <div class="flex-grow-1" style="min-width:0"><div class="tt text-truncate">{{ $a->items->first()?->service_name }}</div><div class="ss">{{ $a->localStartsAt()->format('d M, h:i A') }}</div></div>
                        <span class="fw-bold text-danger text-nowrap">{{ $a->money($a->outstandingMinor()) }}</span>
                        <i class="fas fa-chevron-right small text-muted"></i>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="abs-card p-3 p-md-4 mb-3" id="abs-docs">
            <h2 class="h6 fw-bold mb-2"><i class="fas fa-file-medical me-1" style="color:#ec4899"></i> Prescriptions &amp; reports</h2>
            @forelse($documents as $doc)
                <a href="{{ $doc->url(null, 30) }}" target="_blank" rel="noopener" class="item">
                    <span class="d-grid rounded-3 flex-shrink-0" style="width:2.1rem;height:2.1rem;place-items:center;background:{{ $doc->isImage() ? '#ede9fe' : '#fee2e2' }};color:{{ $doc->isImage() ? '#7c3aed' : '#dc2626' }}">
                        <i class="fas {{ $doc->isImage() ? 'fa-file-image' : 'fa-file-pdf' }}"></i></span>
                    <div class="flex-grow-1" style="min-width:0"><div class="tt text-truncate">{{ $doc->title ?: $doc->original_name }}</div><div class="ss">{{ $doc->created_at?->format('d M Y') }} · {{ $doc->human_size }}</div></div>
                    <i class="fas fa-external-link-alt small text-muted"></i>
                </a>
            @empty
                <div class="small text-muted">No documents yet. When your doctor shares a prescription or report, it appears here.</div>
            @endforelse
        </div>

        <div class="abs-card p-3 p-md-4">
            <h2 class="h6 fw-bold mb-2"><i class="fas fa-redo me-1 text-primary"></i> Book again</h2>
            @forelse($again as $a)
                <a href="{{ route('abs.public.show', $a->business->slug) }}" class="item">
                    <span class="abs-doc-avatar" style="background:linear-gradient(135deg,#0ea5e9,#6366f1);color:#fff">{{ $initials($a->staff?->name) }}</span>
                    <div class="flex-grow-1" style="min-width:0"><div class="tt text-truncate">{{ $a->items->first()?->service_name }}</div><div class="ss text-truncate">{{ $a->staff?->name }} · {{ $a->business?->name }}</div></div>
                    <span class="btn btn-sm btn-glass py-0 px-2">Book</span>
                </a>
            @empty
                <a href="{{ route('abs.public.directory') }}" class="item"><div class="tt">Browse places to book</div><i class="fas fa-chevron-right small text-muted ms-auto"></i></a>
            @endforelse
        </div>
    </div>
</div>
@endsection

@push('js')
<script>
(function () {
    const el = document.getElementById('abs-countdown');
    if (!el) return;
    const start = new Date(el.dataset.start);
    const tick = () => {
        const ms = start - new Date();
        if (ms <= 0) { el.textContent = 'starting now'; return; }
        const d = Math.floor(ms / 864e5), h = Math.floor(ms / 36e5) % 24, m = Math.floor(ms / 6e4) % 60;
        el.textContent = 'in ' + (d ? d + 'd ' : '') + (d || h ? h + 'h ' : '') + m + 'm';
    };
    tick(); setInterval(tick, 30000);
})();
</script>
@endpush
