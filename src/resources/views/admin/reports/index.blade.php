@extends('me::master')

@section('title', 'Booking Reports')

@push('buttons')
    @include('abs::admin.partials.business-bar')
@endpush

@php $fmt = fn ($minor, $cur) => \ME\Abs\Support\Currency::format((int) $minor, $cur); $q = ['from' => $from, 'to' => $to]; @endphp
@section('content')
<div class="card glass-card w-100 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-sm-auto"><label class="small fw-bold">From</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
        <div class="col-sm-auto"><label class="small fw-bold">To</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
        <div class="col-sm-auto"><button class="btn btn-sm btn-encodex-search rounded"><i class="fas fa-filter"></i> Apply</button></div>
        @if($canExport)
            <div class="col-sm text-sm-end">
                @foreach(['appointments' => 'Appointments', 'payments' => 'Payments', 'services' => 'By service', 'staff' => 'By staff'] as $type => $label)
                    <a href="{{ route('abs.reports.export', ['type' => $type] + $q) }}" class="btn btn-sm btn-outline-secondary no-loader" download><i class="fas fa-file-csv me-1"></i>{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </form>
    <p class="small text-muted mt-2 mb-0">Dates in {{ $business->timezone }}. Bookings are counted by appointment date; payments by payment date; refunds by completion date.</p>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card shadow h-100"><div class="card-body">
            <h6 class="fw-bold">Bookings ({{ $summary['total_bookings'] }})</h6>
            <table class="table table-sm mb-0">
                @foreach($summary['counts'] as $status => $count)
                    @if($count)<tr><td>{{ \ME\Abs\Enums\AppointmentStatus::from($status)->label() }}</td><td class="text-end">{{ $count }}</td></tr>@endif
                @endforeach
            </table>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow h-100"><div class="card-body">
            <h6 class="fw-bold">Money</h6>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th></th>@foreach($summary['money'] as $cur => $row)<th class="text-end">{{ $cur }}</th>@endforeach</tr></thead>
                <tr><td>Gross booking value <i class="fas fa-info-circle text-muted" title="Total price of billable bookings in the period. Not money received."></i></td>@foreach($summary['money'] as $cur => $row)<td class="text-end">{{ $fmt($row['gross'], $cur) }}</td>@endforeach</tr>
                <tr><td>Collected payments</td>@foreach($summary['money'] as $cur => $row)<td class="text-end">{{ $fmt($row['collected'], $cur) }}</td>@endforeach</tr>
                <tr><td>Refunds</td>@foreach($summary['money'] as $cur => $row)<td class="text-end">−{{ $fmt($row['refunded'], $cur) }}</td>@endforeach</tr>
                <tr class="fw-bold"><td>Net collected</td>@foreach($summary['money'] as $cur => $row)<td class="text-end">{{ $fmt($row['net_collected'], $cur) }}</td>@endforeach</tr>
                <tr><td>Outstanding balances</td>@foreach($summary['money'] as $cur => $row)<td class="text-end text-danger">{{ $fmt($row['outstanding'], $cur) }}</td>@endforeach</tr>
            </table>
            </div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    @foreach(['By service' => $services, 'By staff' => $staff] as $title => $rows)
        <div class="col-lg-6">
            <div class="card glass-card w-100 h-100">
                <h6 class="fw-bold">{{ $title }}</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-encodex">
                        <thead class="text-center"><tr><th>Name</th><th>Bookings</th><th>Done</th><th>Cancelled</th><th>Gross value</th><th>Net paid</th></tr></thead>
                        <tbody>
                        @forelse($rows as $r)
                            <tr><td>{{ $r->label }}</td><td class="text-center">{{ $r->bookings }}</td><td class="text-center">{{ $r->completed }}</td><td class="text-center">{{ $r->cancelled }}</td>
                                <td class="text-end">{{ $fmt($r->gross, $r->currency) }}</td><td class="text-end">{{ $fmt($r->net_paid, $r->currency) }}</td></tr>
                        @empty
                            <x-abs::empty-row colspan="6" text="No bookings in this period." />
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
