@extends('abs::layouts.public')

@section('title', 'My bookings')
@section('robots', 'noindex,nofollow')
@section('width', '860px')

@php
    // A confirmed visit whose time has passed is shown as "Past visit", not "Confirmed".
    $statusOf = fn ($a) => $a->status->value === 'confirmed' && $a->ends_at->isPast() ? ['Past visit', 'secondary'] : [$a->status->label(), $a->status->color()];
    $tabs = ['all' => 'All', 'upcoming' => 'Upcoming', 'past' => 'Past', 'cancelled' => 'Cancelled'];
@endphp

@push('css')
<style>
    .abs-tabs { display: flex; gap: .35rem; padding: .3rem; border-radius: 14px; background: rgba(255, 255, 255, .5); border: 1px solid var(--glass-border); overflow-x: auto; scrollbar-width: none; }
    .abs-tabs a { flex: none; padding: .45rem .9rem; border-radius: 10px; font-weight: 600; font-size: .88rem; color: #475569; text-decoration: none; white-space: nowrap; }
    .abs-tabs a span { font-size: .72rem; margin-left: .3rem; padding: .05rem .45rem; border-radius: 999px; background: rgba(148, 163, 184, .2); }
    .abs-tabs a.active { background: #fff; color: #4338ca; box-shadow: 0 2px 8px rgba(15, 23, 42, .08); }
    .abs-tabs a.active span { background: #eef2ff; color: #4338ca; }

    .abs-bk { display: flex; align-items: center; gap: .9rem; padding: .85rem 1rem; border-radius: 16px; text-decoration: none; color: var(--abs-ink);
        background: var(--glass-bg); border: 1px solid var(--glass-border); box-shadow: var(--glass-shadow); backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur);
        transition: transform .12s, background .12s; }
    .abs-bk:hover { transform: translateY(-2px); background: rgba(255, 255, 255, .85); color: var(--abs-ink); }
    .abs-bk .date { width: 3.6rem; flex: none; text-align: center; border-radius: 12px; padding: .35rem 0; background: #fff; box-shadow: 0 2px 8px rgba(15, 23, 42, .06); line-height: 1.1; }
    .abs-bk .date .m { font-size: .66rem; font-weight: 700; color: #0ea5e9; text-transform: uppercase; }
    .abs-bk .date .d { font-size: 1.35rem; font-weight: 800; }
    .abs-bk .date .y { font-size: .64rem; color: var(--abs-muted); }
    .abs-bk.upcoming .date { background: linear-gradient(135deg, #0ea5e9, #6366f1); color: #fff; }
    .abs-bk.upcoming .date .m, .abs-bk.upcoming .date .y { color: rgba(255, 255, 255, .85); }
    .abs-bk .title { font-weight: 700; font-size: .95rem; line-height: 1.25; }
    .abs-bk .meta { font-size: .78rem; color: var(--abs-muted); }
    .abs-bk .right { display: flex; flex-direction: column; align-items: flex-end; gap: .3rem; flex: none; }
    .abs-bk .chev { color: #cbd5e1; flex: none; }
    .abs-bk.closed { opacity: .7; }
    .abs-sep { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--abs-muted); margin: 1rem 0 .5rem .3rem; }
</style>
@endpush

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h1 class="h3 fw-bold mb-0">My bookings</h1>
        <div class="text-muted small">Tap a booking to see its details, pay, download reports or make changes.</div>
    </div>
</div>

<nav class="abs-tabs mb-3" aria-label="Filter bookings">
    @foreach($tabs as $key => $label)
        <a href="{{ route('abs.account.dashboard', $key === 'all' ? [] : ['tab' => $key]) }}" class="{{ $tab === $key ? 'active' : '' }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}<span>{{ $counts[$key] }}</span></a>
    @endforeach
</nav>

@if($bookings->isEmpty())
    <div class="abs-card p-5 text-center">
        <i class="far fa-calendar-alt fa-2x text-muted mb-3"></i>
        <div class="fw-semibold mb-1">{{ $tab === 'all' ? 'You have no bookings yet' : 'Nothing here' }}</div>
        <div class="small text-muted mb-3">Bookings you make while signed in appear here.</div>
        <a href="{{ route('abs.public.directory') }}" class="btn btn-primary px-4">Book an appointment</a>
    </div>
@else
    @php $shownUpcoming = $shownPast = false; @endphp
    <div class="d-grid gap-2">
        @foreach($bookings as $a)
            @php
                $start = $a->localStartsAt(); [$label, $color] = $statusOf($a);
                $isUpcoming = ! $a->ends_at->isPast() && ! in_array($a->status->value, ['cancelled', 'rejected', 'expired']);
                $isClosed = in_array($a->status->value, ['cancelled', 'rejected', 'expired', 'no_show']);
                $due = $isUpcoming ? $a->outstandingMinor() : 0;
            @endphp
            @if($tab === 'all' && $isUpcoming && ! $shownUpcoming)<div class="abs-sep">Upcoming</div>@php $shownUpcoming = true; @endphp@endif
            @if($tab === 'all' && ! $isUpcoming && ! $shownPast)<div class="abs-sep">Earlier</div>@php $shownPast = true; @endphp@endif
            <a href="{{ route('abs.account.booking', $a->booking_reference) }}" class="abs-bk {{ $isUpcoming ? 'upcoming' : '' }} {{ $isClosed ? 'closed' : '' }}">
                <div class="date"><div class="m">{{ $start->format('M') }}</div><div class="d">{{ $start->format('d') }}</div><div class="y">{{ $start->format('Y') }}</div></div>
                <div class="flex-grow-1" style="min-width:0">
                    <div class="title text-truncate">{{ $a->items->first()?->service_name }}</div>
                    <div class="meta text-truncate"><i class="far fa-clock me-1"></i>{{ $start->format('D, h:i A') }} · {{ $a->staff?->name }}</div>
                    <div class="meta text-truncate d-none d-sm-block"><i class="fas fa-store me-1"></i>{{ $a->business?->name }}{{ $a->branch ? ' · '.$a->branch->name : '' }}</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mt-1 d-sm-none">
                        <span class="badge abs-badge-soft bg-{{ $color }}-subtle text-{{ $color }}">{{ $label }}</span>
                        @if($due > 0)<span class="small fw-bold text-danger">{{ $a->money($due) }} due</span>@endif
                    </div>
                </div>
                <div class="right d-none d-sm-flex">
                    <span class="badge abs-badge-soft bg-{{ $color }}-subtle text-{{ $color }}">{{ $label }}</span>
                    @if($due > 0)<span class="small fw-bold text-danger text-nowrap">{{ $a->money($due) }} due</span>@endif
                </div>
                <i class="fas fa-chevron-right chev"></i>
            </a>
        @endforeach
    </div>
    <div class="mt-3">{{ $bookings->links('pagination::bootstrap-5') }}</div>
@endif
@endsection
