@extends('me::master')

@section('title', 'Staff')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @component('me::components.btn.add-button', ['route' => route('abs.staff.create'), 'text' => 'Add Staff', 'class' => 'btn-encodex-create'])
    @endcomponent
@endpush

@section('content')
<div class="card glass-card w-100">
    <form method="GET" class="mb-3">
        <div class="row g-2">
            <div class="col-md"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name" value="{{ request('q') }}"></div>
            <div class="col-md-auto"><button class="btn btn-sm btn-encodex-search rounded"><i class="fas fa-search"></i> Search</button></div>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>Name</th><th>Title</th><th>Login</th><th>Branches</th><th>Services</th><th>Hours set</th><th>Bookable</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($staff as $member)
                <tr>
                    <td>{{ $member->name }}<div class="small text-muted">{{ $member->email }}</div></td>
                    <td>{{ $member->title }}</td>
                    <td class="small">{{ $member->user?->email ?? '—' }}</td>
                    <td class="small">{{ $member->branches->pluck('name')->join(', ') ?: 'All' }}</td>
                    <td class="text-center">{{ $member->services_count }}</td>
                    <td class="text-center">
                        @if($member->schedules_count)<span class="badge bg-success-subtle text-success">yes</span>@else<span class="badge bg-warning-subtle text-warning">no hours</span>@endif
                    </td>
                    <td class="text-center">
                        @if($member->is_active && $member->accepts_bookings)<span class="badge bg-success-subtle text-success">Yes</span>@else<span class="badge bg-secondary-subtle text-secondary">No</span>@endif
                    </td>
                    <td class="text-center text-nowrap">
                        <a href="{{ route('abs.staff.schedule', $member->id) }}" class="btn btn-sm btn-encodex-show" title="Hours & time off"><i class="fas fa-clock"></i></a>
                        <a href="{{ route('abs.staff.edit', $member->id) }}" class="btn btn-sm btn-encodex-edit" title="Edit"><i class="fas fa-edit"></i></a>
                        <x-abs::delete-button :action="route('abs.staff.destroy', $member->id)" confirm="Delete this staff member? Past appointments are kept." />
                    </td>
                </tr>
            @empty
                <x-abs::empty-row colspan="8" />
            @endforelse
            </tbody>
        </table>
    </div>
    @if($staff->hasPages())<div class="mt-3">{{ $staff->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
