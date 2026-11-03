@extends('me::master')

@section('title', 'Services')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @component('me::components.btn.add-button', ['route' => route('abs.services.create'), 'text' => 'Add Service', 'class' => 'btn-encodex-create'])
    @endcomponent
@endpush

@section('content')
<div class="card glass-card w-100">
    <form method="GET" class="mb-3">
        <div class="row g-2">
            <div class="col-md"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name" value="{{ request('q') }}"></div>
            <div class="col-md">
                <select name="category" class="form-select form-select-sm">
                    <option value="">All categories</option>
                    @foreach($categories as $c)<option value="{{ $c->id }}" @selected((string) request('category') === (string) $c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-sm btn-encodex-search rounded"><i class="fas fa-search"></i> Search</button>
                <a href="{{ url()->current() }}" class="btn btn-sm btn-encodex-clear rounded"><i class="fas fa-eraser"></i> Reset</a>
            </div>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>Name</th><th>Category</th><th>Duration</th><th>Buffers</th><th>Price</th><th>Deposit</th><th>Staff</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($services as $service)
                <tr>
                    <td>{{ $service->name }}</td>
                    <td>{{ $service->category?->name ?? '—' }}</td>
                    <td class="text-center">{{ $service->duration_minutes }} min</td>
                    <td class="text-center small">{{ $service->buffer_before_minutes }} / {{ $service->buffer_after_minutes }} min</td>
                    <td class="text-end">{{ \ME\Abs\Support\Currency::format($service->price_minor, $business->currency) }}</td>
                    <td class="text-center small">
                        @switch($service->deposit_type->value)
                            @case('fixed') {{ \ME\Abs\Support\Currency::format($service->deposit_value, $business->currency) }} @break
                            @case('percent') {{ $service->deposit_value }}% @break
                            @default —
                        @endswitch
                    </td>
                    <td class="text-center">
                        @if($service->staff_count)
                            {{ $service->staff_count }}
                        @else
                            <span class="badge bg-warning-subtle text-warning" title="Not bookable until a staff member offers it">none</span>
                        @endif
                    </td>
                    <td class="text-center">{!! $service->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-danger-subtle text-danger">Inactive</span>' !!}</td>
                    <td class="text-center">
                        <a href="{{ route('abs.services.edit', $service->id) }}" class="btn btn-sm btn-encodex-edit"><i class="fas fa-edit"></i></a>
                        <x-abs::delete-button :action="route('abs.services.destroy', $service->id)" confirm="Delete this service? Existing bookings keep their details." />
                    </td>
                </tr>
            @empty
                <x-abs::empty-row colspan="9" />
            @endforelse
            </tbody>
        </table>
    </div>
    @if($services->hasPages())<div class="mt-3">{{ $services->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
