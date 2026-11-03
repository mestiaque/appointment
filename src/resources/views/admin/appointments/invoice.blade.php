<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->number }} — {{ $appointment->business->name }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print { .no-print { display: none !important; } body { font-size: 12px; } }</style>
</head>
<body class="bg-white">
@php $money = fn ($m) => $appointment->money($m); $tz = $appointment->timezone; @endphp
<div class="container my-4" style="max-width: 800px">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            @if($logo = $appointment->business->mediaUrl('logo', 'thumb'))<img src="{{ $logo }}" alt="" style="max-height:60px" class="mb-2"><br>@endif
            <h4 class="mb-0">{{ $appointment->business->name }}</h4>
            <div class="small text-muted">{{ $appointment->business->address }}<br>{{ $appointment->business->email }} {{ $appointment->business->phone }}</div>
        </div>
        <div class="text-end">
            <h3 class="mb-0">{{ $appointment->paid_minor > 0 && $appointment->outstandingMinor() === 0 ? 'Receipt' : 'Invoice' }}</h3>
            <div>{{ $invoice->number }}</div>
            <div class="small text-muted">Issued {{ $invoice->issued_at->setTimezone($tz)->format('d M Y') }}</div>
            <div class="small">Booking {{ $appointment->booking_reference }}</div>
        </div>
    </div>
    <p><b>Billed to:</b> {{ $appointment->customer_name }}<br>{{ $appointment->customer_email }} {{ $appointment->customer_phone }}</p>
    <table class="table table-bordered">
        <thead><tr><th>Service</th><th>Date</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        @foreach($appointment->items as $item)
            <tr>
                <td>{{ $item->service_name }} ({{ $item->duration_minutes }} min)<div class="small text-muted">with {{ $appointment->staff?->name }}</div></td>
                <td>{{ $appointment->localStartsAt()->format('d M Y h:i A') }}</td>
                <td class="text-end">{{ $item->quantity }}</td>
                <td class="text-end">{{ $money($item->unit_price_minor) }}</td>
                <td class="text-end">{{ $money($item->line_total_minor) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end">{{ $money($invoice->subtotal_minor) }}</td></tr>
            @if($invoice->discount_minor)<tr><td colspan="4" class="text-end">Discount</td><td class="text-end">−{{ $money($invoice->discount_minor) }}</td></tr>@endif
            @if($invoice->tax_minor)<tr><td colspan="4" class="text-end">Tax</td><td class="text-end">{{ $money($invoice->tax_minor) }}</td></tr>@endif
            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="text-end">{{ $money($invoice->total_minor) }}</td></tr>
            <tr><td colspan="4" class="text-end">Paid</td><td class="text-end">{{ $money($appointment->paid_minor) }}</td></tr>
            @if($appointment->refunded_minor)<tr><td colspan="4" class="text-end">Refunded</td><td class="text-end">−{{ $money($appointment->refunded_minor) }}</td></tr>@endif
            <tr class="fw-bold"><td colspan="4" class="text-end">Balance due</td><td class="text-end">{{ $money($appointment->outstandingMinor()) }}</td></tr>
        </tfoot>
    </table>
    @if($appointment->payments->isNotEmpty())
        <h6>Payments</h6>
        <ul class="small">
            @foreach($appointment->payments->filter(fn ($p) => in_array($p->status, \ME\Abs\Enums\PaymentRecordStatus::captured(), true)) as $p)
                <li>{{ $p->paid_at?->setTimezone($tz)->format('d M Y H:i') }} — {{ ucfirst($p->provider) }} — {{ $money($p->amount_minor) }}</li>
            @endforeach
        </ul>
    @endif
    <button class="btn btn-primary no-print" onclick="window.print()">Print</button>
</div>
</body>
</html>
