<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Booking slip {{ $appointment->booking_reference }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; font-family: 'Source Sans 3', system-ui, sans-serif; }
        .slip { max-width: 640px; margin: 2rem auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); overflow: hidden; }
        .slip-head { background: linear-gradient(135deg, #0ea5e9, #6366f1); color: #fff; padding: 1.4rem 1.6rem; }
        .ref { font-family: ui-monospace, monospace; font-size: 1.6rem; font-weight: 700; letter-spacing: .08em; }
        .slip-body { padding: 1.4rem 1.6rem; }
        .row-l { display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; border-bottom: 1px dashed #e2e8f0; }
        .row-l span { color: #64748b; } .row-l b { text-align: right; }
        .cut { border-top: 2px dashed #cbd5e1; margin: 0 1.6rem; }
        @media print { body { background: #fff; } .slip { box-shadow: none; margin: 0 auto; } .no-print { display: none !important; }
            .slip-head { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
@php $a = $appointment; $item = $a->items->first(); $start = $a->localStartsAt(); @endphp
<div class="slip">
    <div class="slip-head d-flex justify-content-between align-items-start gap-3">
        <div>
            <div class="fw-bold fs-5">{{ $a->business->name }}</div>
            <div class="small opacity-75">{{ $a->business->address }}</div>
            <div class="small opacity-75">{{ $a->business->phone }}</div>
        </div>
        <div class="text-end">
            <div class="small text-uppercase opacity-75">Booking slip</div>
            <div class="ref">{{ $a->booking_reference }}</div>
        </div>
    </div>
    <div class="slip-body">
        <div class="text-center mb-3">
            <div class="text-muted small text-uppercase">Appointment</div>
            <div class="fs-3 fw-bold">{{ $start->format('l, d F Y') }}</div>
            <div class="fs-5">{{ $start->format('h:i A') }} – {{ $a->localEndsAt()->format('h:i A') }} <span class="small text-muted">({{ $a->timezone }})</span></div>
        </div>
        <div class="row-l"><span>Patient / customer</span><b>{{ $a->customer_name }}</b></div>
        <div class="row-l"><span>Phone</span><b>{{ $a->customer_phone ?: '—' }}</b></div>
        <div class="row-l"><span>Service</span><b>{{ $item?->service_name }} ({{ $item?->duration_minutes }} min)</b></div>
        <div class="row-l"><span>With</span><b>{{ $a->staff?->name }}</b></div>
        @if($a->branch)<div class="row-l"><span>Location</span><b>{{ $a->branch->name }}<br><small class="fw-normal">{{ $a->branch->address }}</small></b></div>@endif
        <div class="row-l"><span>Status</span><b>{{ $a->status->label() }}</b></div>
        <div class="row-l"><span>Total</span><b>{{ $a->money($a->total_minor) }}</b></div>
        <div class="row-l"><span>Paid</span><b>{{ $a->money($a->paid_minor - $a->refunded_minor) }}</b></div>
        <div class="row-l" style="border:0"><span>Amount due</span><b class="fs-5">{{ $a->money($a->outstandingMinor()) }}</b></div>
    </div>
    <div class="cut"></div>
    <div class="slip-body small text-muted">
        Please arrive 10 minutes early and show this slip (or the booking number) at the counter.
        Printed {{ now($a->timezone)->format('d M Y, h:i A') }}.
    </div>
</div>
<div class="text-center mb-4 no-print">
    <button class="btn btn-primary px-4" onclick="window.print()">Print</button>
    <button class="btn btn-outline-secondary" onclick="history.back()">Back</button>
</div>
<script>if (new URLSearchParams(location.search).has('auto')) window.addEventListener('load', () => window.print());</script>
</body>
</html>
