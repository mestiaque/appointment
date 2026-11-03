@extends('me::master')

@section('title', 'Payments')

@push('buttons')
    @include('abs::admin.partials.business-bar')
@endpush

@section('content')
<div class="card glass-card w-100">
    <form method="GET" class="mb-3">
        <div class="row g-2">
            <div class="col-md">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    @foreach(\ME\Abs\Enums\PaymentRecordStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ ucwords(str_replace('_', ' ', $s->value)) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md">
                <select name="provider" class="form-select form-select-sm">
                    <option value="">All methods</option>
                    @foreach(['cash' => 'Cash', 'manual' => 'Other / manual', 'stripe' => 'Stripe', 'paypal' => 'PayPal'] as $k => $v)<option value="{{ $k }}" @selected(request('provider') === $k)>{{ $v }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-sm btn-encodex-search rounded"><i class="fas fa-search"></i> Filter</button>
                <a href="{{ url()->current() }}" class="btn btn-sm btn-encodex-clear rounded"><i class="fas fa-eraser"></i> Reset</a>
            </div>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>#</th><th>Booking</th><th>Customer</th><th>Method</th><th>Purpose</th><th>Amount</th><th>Refunded</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            @forelse($payments as $p)
                <tr>
                    <td>{{ $p->id }}</td>
                    <td><a href="{{ route('abs.appointments.show', $p->appointment_id) }}">{{ $p->appointment?->booking_reference }}</a></td>
                    <td>{{ $p->appointment?->customer_name }}</td>
                    <td>{{ ucfirst($p->provider) }}</td>
                    <td>{{ ucfirst($p->purpose->value) }}</td>
                    <td class="text-end">{{ \ME\Abs\Support\Currency::format($p->amount_minor, $p->currency) }}</td>
                    <td class="text-end">{{ $p->refunded_minor ? \ME\Abs\Support\Currency::format($p->refunded_minor, $p->currency) : '—' }}</td>
                    <td class="text-center"><x-abs::status-badge :status="$p->status" /></td>
                    <td class="small">{{ ($p->paid_at ?? $p->created_at)?->setTimezone($currentBusiness->timezone)->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <x-abs::empty-row colspan="9" />
            @endforelse
            </tbody>
        </table>
    </div>
    @if($payments->hasPages())<div class="mt-3">{{ $payments->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
