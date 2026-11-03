@extends('me::master')

@section('title', 'Calendar')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @if($can['create'])
        @component('me::components.btn.add-button', ['route' => route('abs.appointments.create'), 'text' => 'New Booking', 'class' => 'btn-encodex-create'])
        @endcomponent
    @endif
@endpush

@push('css')
<style>
    .abs-cal-chip { display: inline-flex; align-items: center; gap: .4rem; border: 1px solid #e5e7eb; background: #fff; border-radius: 999px;
        padding: .25rem .7rem; font-size: .8rem; cursor: pointer; user-select: none; transition: all .15s; }
    .abs-cal-chip .dot { width: .65rem; height: .65rem; border-radius: 50%; background: var(--chip); }
    .abs-cal-chip.off { opacity: .45; }
    .abs-cal-chip.off .dot { background: transparent; border: 2px solid var(--chip); }
    .abs-cal-chip:hover { border-color: var(--chip); }
    .abs-stat { border-radius: .75rem; padding: .6rem .8rem; background: #fff; border: 1px solid #eef0f4; height: 100%; }
    .abs-stat .v { font-size: 1.25rem; font-weight: 700; line-height: 1.2; }
    .abs-stat .l { font-size: .72rem; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; }

    #abs-calendar { --fc-border-color: #edf0f5; --fc-today-bg-color: transparent; --fc-non-business-color: rgba(241, 245, 249, .75);
        --fc-now-indicator-color: #ef4444; --fc-button-bg-color: #fff; --fc-button-text-color: #374151; --fc-button-border-color: #e5e7eb;
        --fc-button-hover-bg-color: #f3f4f6; --fc-button-hover-border-color: #d1d5db; --fc-button-active-bg-color: #2563eb;
        --fc-button-active-border-color: #2563eb; --fc-page-bg-color: #fff; font-size: .85rem; }
    #abs-calendar .fc-button { box-shadow: none !important; font-size: .8rem; padding: .32rem .75rem; border-radius: .5rem; }
    #abs-calendar .fc-button-group > .fc-button { border-radius: 0; }
    #abs-calendar .fc-button-group > .fc-button:first-child { border-top-left-radius: .5rem; border-bottom-left-radius: .5rem; }
    #abs-calendar .fc-button-group > .fc-button:last-child { border-top-right-radius: .5rem; border-bottom-right-radius: .5rem; }
    #abs-calendar .fc-button-active { color: #fff !important; }
    #abs-calendar .fc-toolbar-title { font-size: 1.2rem; font-weight: 700; color: #111827; }
    #abs-calendar .fc-scrollgrid { border-radius: .75rem; overflow: hidden; }
    #abs-calendar .fc-col-header-cell { background: #fff; border-bottom: 1px solid #e5e7eb; }
    #abs-calendar .fc-col-header-cell-cushion { text-decoration: none; padding: .45rem .25rem; display: block; }
    .abs-dh { display: flex; flex-direction: column; align-items: center; gap: 2px; line-height: 1; }
    .abs-dh .wd { font-size: .68rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; }
    .abs-dh .dn { font-size: 1.25rem; font-weight: 600; color: #111827; width: 2.2rem; height: 2.2rem; display: grid; place-items: center; border-radius: 50%; }
    .abs-dh .cnt { font-size: .65rem; color: #9ca3af; }
    .abs-dh.today .wd { color: #2563eb; }
    .abs-dh.today .dn { background: #2563eb; color: #fff; }
    #abs-calendar .fc-timegrid-slot { height: 3rem; border-bottom: 0; }
    #abs-calendar .fc-timegrid-slot-minor { border-top-style: dotted; border-top-color: #f1f5f9; }
    #abs-calendar .fc-timegrid-slot-label-cushion { color: #9ca3af; font-size: .72rem; font-weight: 500; }
    #abs-calendar .fc-timegrid-now-indicator-line { border-width: 2px 0 0; }
    #abs-calendar .fc-timegrid-now-indicator-arrow { border-width: 6px 0 6px 7px; }
    #abs-calendar .fc-day-past .fc-timegrid-col-frame { background-image: linear-gradient(rgba(255,255,255,.35), rgba(255,255,255,.35)); }

    #abs-calendar .abs-ev { border: 0 !important; border-radius: .45rem; cursor: pointer; overflow: hidden;
        box-shadow: inset 3px 0 0 var(--abs-c), 0 1px 2px rgba(15, 23, 42, .08); transition: transform .1s, box-shadow .1s; }
    #abs-calendar .fc-timegrid-event.abs-ev:hover { transform: translateY(-1px); box-shadow: inset 3px 0 0 var(--abs-c), 0 4px 10px rgba(15, 23, 42, .12); z-index: 5 !important; }
    #abs-calendar .fc-timegrid-event-harness { margin-right: 2px; }
    .abs-ev-body { padding: 2px 5px 2px 8px; line-height: 1.2; color: #111827; height: 100%; }
    .abs-ev-time { font-size: .68rem; color: #4b5563; font-weight: 500; white-space: nowrap; }
    .abs-ev-name { font-weight: 600; font-size: .78rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .abs-ev-svc { font-size: .7rem; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .abs-ev-line { display: flex; gap: .35rem; align-items: baseline; white-space: nowrap; overflow: hidden; }
    .abs-ev-line .abs-ev-name { flex: 1; min-width: 0; }
    .abs-ev-icon { font-size: .65rem; color: var(--abs-c); flex: none; }
    .abs-ev-body > .abs-ev-icon { float: right; margin: 2px 0 0 4px; }
    .abs-status-cancelled, .abs-status-rejected { opacity: .5; }
    .abs-status-cancelled .abs-ev-name, .abs-status-rejected .abs-ev-name { text-decoration: line-through; }
    .abs-status-no_show { opacity: .55; }
    .abs-status-completed { filter: saturate(.55); }
    .abs-status-in_progress { box-shadow: inset 3px 0 0 var(--abs-c), 0 0 0 2px var(--abs-c) !important; }
    #abs-calendar .fc-daygrid-event.abs-ev { padding: 1px 2px; margin-top: 2px; }
    #abs-calendar .fc-col-header-cell-cushion, #abs-calendar .fc-daygrid-day-number { color: #374151; text-decoration: none; }
    #abs-calendar .fc-dayGridMonth-view .fc-col-header-cell-cushion { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; font-weight: 600; }
    #abs-calendar .fc-daygrid-day-number { font-size: .8rem; font-weight: 600; padding: .35rem .45rem; }
    #abs-calendar .fc-day-today .fc-daygrid-day-number { background: #2563eb; color: #fff; border-radius: 999px; min-width: 1.7rem; text-align: center; margin: .2rem; padding: .2rem .45rem; }
    #abs-calendar .fc-day-other .fc-daygrid-day-number { color: #cbd5e1; }
    #abs-calendar .fc-daygrid-more-link { font-size: .72rem; color: #2563eb; text-decoration: none; font-weight: 600; }
    #abs-calendar .fc-list-event:hover td { background: #f8fafc; }
    #abs-calendar .fc-list-day-cushion { background: #f8fafc; padding: .55rem .9rem; }
    #abs-calendar .fc-list-day-cushion a { text-decoration: none; }
    #abs-calendar .fc-list-event td { padding: .55rem .9rem; }
    #abs-calendar .fc-list-event-dot { border-color: var(--abs-c) !important; }

    .abs-queue-item { display: flex; gap: .6rem; align-items: center; padding: .55rem .6rem; border-radius: .6rem; cursor: pointer; border: 1px solid transparent; }
    .abs-queue-item:hover { background: #f8fafc; border-color: #eef0f4; }
    .abs-queue-item .t { font-size: .75rem; font-weight: 600; color: #374151; width: 3.6rem; flex: none; align-self: flex-start; padding-top: 2px; }
    .abs-queue-item .badge { font-size: .62rem; font-weight: 600; }
    .abs-queue-item .bar { width: 3px; align-self: stretch; border-radius: 3px; background: var(--abs-c); flex: none; }
    .abs-queue-item .n { font-weight: 600; font-size: .83rem; line-height: 1.2; }
    .abs-queue-item .s { font-size: .72rem; color: #6b7280; }
    .abs-queue-item.now { background: #eff6ff; border-color: #bfdbfe; }
    .abs-queue-item.done { opacity: .5; }
    .abs-queue-head { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; font-weight: 600; margin: .75rem .2rem .3rem; }

    @media (max-width: 767.98px) {
        #abs-calendar .fc-toolbar { gap: .4rem; }
        #abs-calendar .fc-toolbar-title { font-size: 1rem; }
        #abs-calendar .fc-button { padding: .25rem .55rem; }
    }
</style>
@endpush

@section('content')
<div class="card glass-card w-100 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="small text-muted me-1"><i class="fas fa-user-md me-1"></i>Staff</span>
        <div id="abs-staff-chips" class="d-flex flex-nowrap flex-md-wrap gap-2 overflow-auto pb-1" style="max-width: 100%;">
            @foreach($staff as $s)
                <span class="abs-cal-chip text-nowrap" data-id="{{ $s['id'] }}" style="--chip: {{ $s['color'] }}" title="{{ $s['title'] }}" role="button" tabindex="0" aria-pressed="true">
                    <span class="dot"></span>{{ $s['name'] }}
                </span>
            @endforeach
        </div>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <select id="abs-status-filter" class="form-select form-select-sm w-auto" aria-label="Show">
                <option value="">All appointments</option>
                <option value="pending,confirmed,checked_in,in_progress,awaiting_payment">Upcoming &amp; active</option>
                <option value="pending,awaiting_payment">Waiting for approval / payment</option>
                <option value="completed">Completed</option>
                <option value="cancelled,rejected,no_show">Cancelled &amp; no-show</option>
            </select>
            <input type="date" id="abs-goto" class="form-control form-control-sm w-auto" aria-label="Go to date">
        </div>
    </div>
</div>

<div class="row g-2 mb-3 flex-nowrap flex-md-wrap overflow-auto pb-1" id="abs-stats">
    <div class="col-5 col-sm-4 col-md"><div class="abs-stat"><div class="v" data-stat="total">–</div><div class="l">Appointments</div></div></div>
    <div class="col-5 col-sm-4 col-md"><div class="abs-stat"><div class="v text-primary" data-stat="upcoming">–</div><div class="l">Upcoming / active</div></div></div>
    <div class="col-5 col-sm-4 col-md"><div class="abs-stat"><div class="v text-warning" data-stat="pending">–</div><div class="l">Waiting approval</div></div></div>
    <div class="col-5 col-sm-4 col-md"><div class="abs-stat"><div class="v text-success" data-stat="completed">–</div><div class="l">Completed</div></div></div>
    <div class="col-5 col-sm-4 col-md"><div class="abs-stat"><div class="v text-secondary" data-stat="missed">–</div><div class="l">Cancelled / no-show</div></div></div>
</div>

<div class="row g-3">
<div class="col-xl-9">
<div class="card glass-card w-100">
    <div class="d-flex flex-wrap justify-content-between small text-muted mb-2 gap-2">
        <span><i class="far fa-clock me-1"></i>Times in {{ $business->timezone }}. Grey = outside working hours.</span>
        <span>
            @if($can['create'])<i class="fas fa-mouse-pointer me-1"></i>Click an empty time to book.@endif
            @if($can['reschedule'])<i class="fas fa-arrows-alt ms-2 me-1"></i>Drag to reschedule.@endif
        </span>
    </div>
    <div id="abs-calendar"></div>
</div>
</div>
<div class="col-xl-3">
    <div class="card glass-card w-100 position-sticky" style="top: 1rem;">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0"><i class="fas fa-list-ol me-1 text-primary"></i> Today's queue</h6>
            <span class="badge bg-primary-subtle text-primary" id="abs-queue-count">0</span>
        </div>
        <div class="small text-muted" id="abs-queue-date"></div>
        <div id="abs-queue" class="mt-1"><div class="small text-muted py-3">Loading…</div></div>
    </div>
</div>
</div>

{{-- Moved to <body> by the script: metheme's glass containers would clip a fixed panel. --}}
<div class="offcanvas offcanvas-end" tabindex="-1" id="abs-ev-panel" aria-labelledby="abs-ev-title" style="z-index: 1090;">
    <div class="offcanvas-header border-bottom">
        <div>
            <div class="small text-muted" id="abs-ev-ref"></div>
            <h5 class="offcanvas-title mb-0" id="abs-ev-title"></h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="d-flex gap-2 mb-3" id="abs-ev-badges"></div>
        <dl class="row small mb-3">
            <dt class="col-4 text-muted fw-normal">When</dt><dd class="col-8" id="abs-ev-when"></dd>
            <dt class="col-4 text-muted fw-normal">Service</dt><dd class="col-8" id="abs-ev-service"></dd>
            <dt class="col-4 text-muted fw-normal">With</dt><dd class="col-8" id="abs-ev-staff"></dd>
            <dt class="col-4 text-muted fw-normal">Phone</dt><dd class="col-8" id="abs-ev-phone"></dd>
            <dt class="col-4 text-muted fw-normal">E-mail</dt><dd class="col-8 text-break" id="abs-ev-email"></dd>
            <dt class="col-4 text-muted fw-normal">Total</dt><dd class="col-8" id="abs-ev-total"></dd>
        </dl>
        <div class="d-grid gap-2" id="abs-ev-actions"></div>
        <hr>
        <a href="#" class="btn btn-sm btn-outline-secondary w-100" id="abs-ev-open"><i class="fas fa-external-link-alt me-1"></i>Open full details</a>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
{{-- Named time zones (e.g. Asia/Dhaka) need a date library plugin; without it FullCalendar shows UTC. --}}
<script src="https://cdn.jsdelivr.net/npm/luxon@3.5.0/build/global/luxon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/luxon3@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cfg = {
        tz: @js($business->timezone),
        feed: @js(route('abs.calendar.events')),
        moveUrl: @js(route('abs.calendar.move', ['appointment' => '__ID__'])),
        transitionUrl: @js(route('abs.appointments.transition', ['appointment' => '__ID__'])),
        createUrl: @js($can['create'] ? route('abs.appointments.create') : null),
        canMove: @js($can['reschedule']),
        businessHours: @js($businessHours),
        hiddenDays: @js($hiddenDays),
        slotMinTime: @js($slotMinTime),
        slotMaxTime: @js($slotMaxTime),
        csrf: document.querySelector('meta[name="csrf-token"]').content,
    };
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const notify = (type, msg) => (window.toastr ? toastr[type](msg) : alert(msg));
    const icons = { pending: 'fa-hourglass-half', awaiting_payment: 'fa-credit-card', confirmed: 'fa-check', checked_in: 'fa-door-open',
        in_progress: 'fa-play', completed: 'fa-check-double', cancelled: 'fa-ban', rejected: 'fa-times', no_show: 'fa-user-slash' };

    const panelEl = document.getElementById('abs-ev-panel');
    document.body.appendChild(panelEl);
    const panel = new bootstrap.Offcanvas(panelEl);

    const store = { get: (k, d) => { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };
    const hiddenStaff = new Set(JSON.parse(store.get('abs.cal.hiddenStaff', '[]')));
    const chips = [...document.querySelectorAll('#abs-staff-chips .abs-cal-chip')];
    const statusFilter = document.getElementById('abs-status-filter');
    statusFilter.value = store.get('abs.cal.status', '');
    const paintChips = () => chips.forEach(c => { const off = hiddenStaff.has(c.dataset.id); c.classList.toggle('off', off); c.setAttribute('aria-pressed', String(!off)); });
    paintChips();

    const narrow = window.innerWidth < 768;
    // The week starts the day after the weekly off day (e.g. Saturday when Friday is closed).
    const firstDay = cfg.hiddenDays.length ? (Math.max(...cfg.hiddenDays) + 1) % 7 : 1;

    const calendar = new FullCalendar.Calendar(document.getElementById('abs-calendar'), {
        plugins: [FullCalendar.Luxon3.default],
        timeZone: cfg.tz,
        // Six day-columns are unreadable on a phone, so a remembered week view becomes the list there.
        initialView: (v => (narrow && v === 'timeGridWeek') ? 'listWeek' : v)(store.get('abs.cal.view', narrow ? 'listWeek' : 'timeGridWeek')),
        headerToolbar: narrow
            ? { left: 'prev,next', center: 'title', right: 'today' }
            : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        footerToolbar: narrow ? { center: 'dayGridMonth,timeGridDay,listWeek' } : false,
        buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day', list: 'List' },
        firstDay,
        hiddenDays: cfg.hiddenDays,
        businessHours: cfg.businessHours.length ? cfg.businessHours : false,
        slotMinTime: cfg.slotMinTime,
        slotMaxTime: cfg.slotMaxTime,
        slotLabelInterval: '01:00',
        snapDuration: '00:05:00',
        allDaySlot: false,
        nowIndicator: true,
        height: 'auto',
        expandRows: true,
        dayMaxEvents: 4,
        eventDisplay: 'block',
        eventMinHeight: 22,
        eventShortHeight: 40,
        eventDurationEditable: false,
        selectable: !!cfg.createUrl,
        selectMirror: true,
        noEventsContent: 'No appointments in this period',
        slotDuration: '00:30:00',
        dayHeaderContent: function (arg) {
            const d = luxon.DateTime.fromJSDate(arg.date).setZone(cfg.tz);
            if (arg.view.type === 'timeGridWeek' || arg.view.type === 'timeGridDay') {
                return { html: `<div class="abs-dh ${arg.isToday ? 'today' : ''}"><span class="wd">${d.toFormat('ccc')}</span><span class="dn">${d.toFormat('d')}</span></div>` };
            }
            if (arg.view.type.startsWith('list')) {
                return { html: `<span class="fw-semibold ${arg.isToday ? 'text-primary' : 'text-dark'}">${d.toFormat('cccc, d LLLL')}</span>${arg.isToday ? ' <span class="badge bg-primary ms-1">Today</span>' : ''}` };
            }
            return { html: esc(arg.text) };
        },
        events: function (info, success, failure) {
            if (chips.length && chips.every(c => hiddenStaff.has(c.dataset.id))) { success([]); return; }
            const params = new URLSearchParams({ start: info.startStr, end: info.endStr });
            chips.filter(c => !hiddenStaff.has(c.dataset.id)).forEach(c => params.append('staff_ids[]', c.dataset.id));
            if (statusFilter.value) statusFilter.value.split(',').forEach(s => params.append('statuses[]', s));
            fetch(cfg.feed + '?' + params, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r)).then(success).catch(failure);
        },
        eventContent: function (arg) {
            const p = arg.event.extendedProps;
            const icon = `<i class="fas ${icons[p.status] || 'fa-circle'} abs-ev-icon" title="${esc(p.statusLabel)}"></i>`;
            if (arg.view.type.startsWith('list')) {
                return { html: `<span class="fw-semibold">${esc(arg.event.title)}</span> <span class="text-muted">· ${esc(p.service)} · ${esc(p.staff)}</span>
                    <span class="badge bg-${p.statusColor}-subtle text-${p.statusColor} ms-1">${esc(p.statusLabel)}</span>` };
            }
            const start = luxon.DateTime.fromJSDate(arg.event.start).setZone(cfg.tz).toFormat('h:mm');
            const minutes = (arg.event.end - arg.event.start) / 60000;
            // Very short visits (and month cells) get one line; 20–35 min get time + name on two lines.
            if (arg.view.type === 'dayGridMonth' || minutes < 20) {
                return { html: `<div class="abs-ev-body"><div class="abs-ev-line">${icon}<span class="abs-ev-time">${start}</span><span class="abs-ev-name">${esc(arg.event.title)}</span></div></div>` };
            }
            if (minutes < 35) {
                return { html: `<div class="abs-ev-body">${icon}<div class="abs-ev-time">${start}</div><div class="abs-ev-name">${esc(arg.event.title)}</div></div>` };
            }
            return { html: `<div class="abs-ev-body">${icon}<div class="abs-ev-time">${esc(arg.timeText)}</div>
                <div class="abs-ev-name">${esc(arg.event.title)}</div><div class="abs-ev-svc">${esc(p.service)}</div></div>` };
        },
        eventDidMount: function (arg) {
            arg.el.style.setProperty('--abs-c', arg.event.borderColor);
            arg.el.title = `${arg.event.title} · ${arg.event.extendedProps.service} · ${arg.event.extendedProps.staff} · ${arg.event.extendedProps.statusLabel}`;
        },
        eventClick: function (arg) {
            arg.jsEvent.preventDefault();
            openPanel(arg.event);
        },
        eventDrop: function (arg) {
            const body = new FormData();
            body.append('start', arg.event.start.toISOString());
            fetch(cfg.moveUrl.replace('__ID__', arg.event.id), { method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf } })
                .then(async r => ({ ok: r.ok, data: await r.json().catch(() => ({})) }))
                .then(({ ok, data }) => {
                    if (!ok) { arg.revert(); notify('error', data.message || 'Could not move this appointment.'); return; }
                    notify('success', data.message);
                    calendar.refetchEvents();
                })
                .catch(() => { arg.revert(); notify('error', 'Network error — nothing was changed.'); });
        },
        select: function (arg) {
            calendar.unselect();
            if (!cfg.createUrl || arg.view.type === 'dayGridMonth') return;
            const params = new URLSearchParams({ date: arg.startStr.slice(0, 10) });
            const visible = chips.filter(c => !hiddenStaff.has(c.dataset.id));
            if (visible.length === 1) params.set('staff', visible[0].dataset.id);
            window.location = cfg.createUrl + '?' + params;
        },
        dateClick: function (arg) {
            if (arg.view.type === 'dayGridMonth') calendar.changeView('timeGridDay', arg.dateStr);
        },
        datesSet: function (arg) { store.set('abs.cal.view', arg.view.type); },
        eventsSet: function (events) { updateStats(events); renderQueue(); },
    });


    // Today's queue: who is in now, who is next, who is waiting — from the same feed, today only.
    const queueEl = document.getElementById('abs-queue');
    let queueTimer = null;
    function renderQueue() {
        clearTimeout(queueTimer);
        queueTimer = setTimeout(loadQueue, 150);
    }
    function loadQueue() {
        const today = luxon.DateTime.now().setZone(cfg.tz).startOf('day');
        document.getElementById('abs-queue-date').textContent = today.toFormat('cccc, d LLLL');
        const params = new URLSearchParams({ start: today.toISO(), end: today.plus({ days: 1 }).toISO() });
        chips.filter(c => !hiddenStaff.has(c.dataset.id)).forEach(c => params.append('staff_ids[]', c.dataset.id));
        fetch(cfg.feed + '?' + params, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(r => r.json())
            .then(list => {
                const active = list.filter(e => !['cancelled', 'rejected', 'no_show'].includes(e.extendedProps.status));
                document.getElementById('abs-queue-count').textContent = active.length;
                if (!active.length) { queueEl.innerHTML = '<div class="small text-muted py-3">No appointments today.</div>'; return; }
                const now = Date.now();
                const groups = { now: [], next: [], done: [] };
                active.forEach(e => {
                    const st = e.extendedProps.status;
                    if (st === 'completed') groups.done.push(e);
                    else if (st === 'in_progress' || st === 'checked_in' || (new Date(e.start) <= now && new Date(e.end) > now)) groups.now.push(e);
                    else groups.next.push(e);
                });
                const item = (e, cls) => {
                    const p = e.extendedProps;
                    const t = luxon.DateTime.fromISO(e.start).setZone(cfg.tz).toFormat('h:mm a');
                    return `<div class="abs-queue-item ${cls}" data-id="${esc(e.id)}" style="--abs-c:${esc(p.staffColor)}">
                        <span class="t">${t}</span><span class="bar"></span>
                        <div class="flex-grow-1" style="min-width:0"><div class="n text-truncate">${esc(e.title)}</div><div class="s text-truncate">${esc(p.staff)} · ${esc(p.service)}</div>
                        <span class="badge bg-${p.statusColor}-subtle text-${p.statusColor} mt-1">${esc(p.statusLabel)}</span></div></div>`;
                };
                queueEl.innerHTML =
                    (groups.now.length ? '<div class="abs-queue-head">In the chamber / waiting</div>' + groups.now.map(e => item(e, 'now')).join('') : '') +
                    (groups.next.length ? '<div class="abs-queue-head">Next</div>' + groups.next.map(e => item(e, '')).join('') : '') +
                    (groups.done.length ? '<div class="abs-queue-head">Done</div>' + groups.done.map(e => item(e, 'done')).join('') : '');
                queueEl.querySelectorAll('.abs-queue-item').forEach(el => el.addEventListener('click', () => {
                    const ev = calendar.getEventById(el.dataset.id);
                    if (ev) { openPanel(ev); } else { const raw = list.find(x => String(x.id) === el.dataset.id); calendar.gotoDate(raw.start); }
                }));
            })
            .catch(() => { queueEl.innerHTML = '<div class="small text-danger py-3">Could not load today\'s queue.</div>'; });
    }
    setInterval(loadQueue, 60000);

    function updateStats(events) {
        const view = calendar.view;
        const inView = events.filter(e => e.start < view.currentEnd && e.end > view.currentStart);
        const count = statuses => inView.filter(e => statuses.includes(e.extendedProps.status)).length;
        const set = (k, v) => { document.querySelector(`[data-stat="${k}"]`).textContent = v; };
        set('total', inView.length);
        set('upcoming', count(['confirmed', 'checked_in', 'in_progress']));
        set('pending', count(['pending', 'awaiting_payment']));
        set('completed', count(['completed']));
        set('missed', count(['cancelled', 'rejected', 'no_show']));
    }

    function openPanel(event) {
        const p = event.extendedProps;
        const fmt = d => luxon.DateTime.fromJSDate(d).setZone(cfg.tz);
        document.getElementById('abs-ev-ref').textContent = p.reference;
        document.getElementById('abs-ev-title').textContent = event.title;
        document.getElementById('abs-ev-badges').innerHTML =
            `<span class="badge bg-${p.statusColor}-subtle text-${p.statusColor}">${esc(p.statusLabel)}</span>
             <span class="badge bg-${p.paymentColor}-subtle text-${p.paymentColor}">${esc(p.payment)}</span>`;
        document.getElementById('abs-ev-when').textContent = fmt(event.start).toFormat('ccc, d LLL yyyy') + ', ' + fmt(event.start).toFormat('h:mm a') + ' – ' + fmt(event.end).toFormat('h:mm a');
        document.getElementById('abs-ev-service').textContent = p.service || '—';
        document.getElementById('abs-ev-staff').innerHTML = `<span class="d-inline-block rounded-circle me-1" style="width:.6rem;height:.6rem;background:${esc(p.staffColor)}"></span>${esc(p.staff)}`;
        document.getElementById('abs-ev-phone').innerHTML = p.phone ? `<a href="tel:${esc(p.phone)}">${esc(p.phone)}</a>` : '—';
        document.getElementById('abs-ev-email').innerHTML = p.email ? `<a href="mailto:${esc(p.email)}">${esc(p.email)}</a>` : '—';
        document.getElementById('abs-ev-total').innerHTML = esc(p.total) + (p.outstanding ? ` <span class="text-danger small">(due ${esc(p.outstanding)})</span>` : '');
        document.getElementById('abs-ev-open').href = p.url;
        document.getElementById('abs-ev-actions').innerHTML = (p.actions || []).map(a => `
            <form method="POST" action="${esc(cfg.transitionUrl.replace('__ID__', event.id))}" class="m-0" data-needs-reason="${a.needsReason ? 1 : 0}">
                <input type="hidden" name="_token" value="${esc(cfg.csrf)}">
                <input type="hidden" name="status" value="${esc(a.status)}">
                <input type="hidden" name="reason" value="">
                <button class="btn btn-sm btn-outline-${esc(a.color)} w-100"><i class="fas fa-${esc(a.icon)} me-1"></i>${esc(a.label)}</button>
            </form>`).join('') || '<div class="small text-muted">No status change is possible right now.</div>';
        panel.show();
    }

    document.getElementById('abs-ev-actions').addEventListener('submit', function (e) {
        const form = e.target;
        if (form.dataset.needsReason === '1') {
            const reason = prompt('Reason (optional, shown to the customer):');
            if (reason === null) { e.preventDefault(); return; }
            form.reason.value = reason;
        }
    });

    chips.forEach(c => {
        const toggle = () => {
            hiddenStaff.has(c.dataset.id) ? hiddenStaff.delete(c.dataset.id) : hiddenStaff.add(c.dataset.id);
            store.set('abs.cal.hiddenStaff', JSON.stringify([...hiddenStaff]));
            paintChips();
            calendar.refetchEvents();
        };
        c.addEventListener('click', toggle);
        c.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    });
    statusFilter.addEventListener('change', () => { store.set('abs.cal.status', statusFilter.value); calendar.refetchEvents(); });
    document.getElementById('abs-goto').addEventListener('change', e => { if (e.target.value) calendar.gotoDate(e.target.value); });

    calendar.render();
});
</script>
@endpush
