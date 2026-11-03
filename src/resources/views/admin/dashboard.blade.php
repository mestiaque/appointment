@extends('me::master')

@section('title', 'Booking Dashboard')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    <a href="{{ route('abs.public.show', $business->slug) }}" target="_blank" rel="noopener" class="btn btn-sm btn-encodex-show" title="Open public booking page"><i class="fas fa-external-link-alt"></i><span class="d-none d-md-inline ms-1">Booking page</span></a>
@endpush

@php $cur = $business->currency; $m = $summary['money'][$cur] ?? null; @endphp
@section('content')
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card shadow h-100"><div class="card-body">
        <div class="small text-muted">Today</div><div class="fs-3 fw-bold">{{ $today }}</div><div class="small">appointments</div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow h-100"><div class="card-body">
        <div class="small text-muted">Waiting for approval</div><div class="fs-3 fw-bold {{ $pendingApproval ? 'text-warning' : '' }}">{{ $pendingApproval }}</div>
        <a class="small" href="{{ route('abs.appointments.index', ['status' => 'pending']) }}">review</a>
    </div></div></div>
    @if($m)
        <div class="col-6 col-lg-3"><div class="card shadow h-100"><div class="card-body">
            <div class="small text-muted">Collected this month</div><div class="fs-5 fw-bold">{{ \ME\Abs\Support\Currency::format($m['net_collected'], $cur) }}</div>
            <div class="small text-muted">payments minus refunds</div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow h-100"><div class="card-body">
            <div class="small text-muted">Booked value this month</div><div class="fs-5 fw-bold">{{ \ME\Abs\Support\Currency::format($m['gross'], $cur) }}</div>
            <div class="small text-muted">outstanding {{ \ME\Abs\Support\Currency::format($m['outstanding'], $cur) }}</div>
        </div></div></div>
    @endif
</div>

@if($business->services()->doesntExist() || $business->staff()->doesntExist())
    <div class="alert alert-info">
        <b>Getting started:</b>
        1) <a href="{{ route('abs.services.create') }}">add a service</a>,
        2) <a href="{{ route('abs.staff.create') }}">add staff</a> and assign the service,
        3) set their working hours, 4) share your booking page.
    </div>
@endif

<div class="card glass-card w-100">
    <h6 class="fw-bold mb-3"><i class="fas fa-calendar-day me-1"></i> Upcoming</h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>When</th><th>Customer</th><th>Service</th><th>Staff</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($upcoming as $a)
                <tr>
                    <td class="text-nowrap">{{ $a->localStartsAt()->format('D d M, h:i A') }}</td>
                    <td>{{ $a->customer_name }}</td>
                    <td>{{ $a->items->first()?->service_name }}</td>
                    <td>{{ $a->staff?->name }}</td>
                    <td class="text-center"><x-abs::status-badge :status="$a->status" /></td>
                    <td class="text-center"><a href="{{ route('abs.appointments.show', $a->id) }}" class="btn btn-sm btn-encodex-show"><i class="fas fa-eye"></i></a></td>
                </tr>
            @empty
                <x-abs::empty-row colspan="6" text="No upcoming appointments." />
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
