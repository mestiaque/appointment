@extends('me::master')

@section('title', 'Branches')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @component('me::components.btn.add-button', ['route' => route('abs.branches.create'), 'text' => 'Add Branch', 'class' => 'btn-encodex-create'])
    @endcomponent
@endpush

@section('content')
<div class="card glass-card w-100">
    <p class="small text-muted">Branches are optional. With no branch, the booking page skips the branch step. Staff without a branch assignment work at every branch.</p>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>Name</th><th>Address</th><th>Phone</th><th>Staff</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($branches as $branch)
                <tr>
                    <td>{{ $branch->name }}</td>
                    <td>{{ $branch->address }}</td>
                    <td>{{ $branch->phone }}</td>
                    <td class="text-center">{{ $branch->staff_count }}</td>
                    <td class="text-center">{!! $branch->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-danger-subtle text-danger">Inactive</span>' !!}</td>
                    <td class="text-center">
                        <a href="{{ route('abs.branches.edit', $branch->id) }}" class="btn btn-sm btn-encodex-edit" title="Edit"><i class="fas fa-edit"></i></a>
                        <x-abs::delete-button :action="route('abs.branches.destroy', $branch->id)" />
                    </td>
                </tr>
            @empty
                <x-abs::empty-row colspan="6" />
            @endforelse
            </tbody>
        </table>
    </div>
    @if($branches->hasPages())<div class="mt-3">{{ $branches->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
