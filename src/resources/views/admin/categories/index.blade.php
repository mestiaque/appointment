@extends('me::master')

@section('title', 'Service Categories')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @component('me::components.btn.add-button', ['route' => route('abs.categories.create'), 'text' => 'Add Category', 'class' => 'btn-encodex-create'])
    @endcomponent
@endpush

@section('content')
<div class="card glass-card w-100">
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>Name</th><th>Services</th><th>Sort</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($categories as $category)
                <tr>
                    <td>{{ $category->name }}</td>
                    <td class="text-center">{{ $category->services_count }}</td>
                    <td class="text-center">{{ $category->sort_order }}</td>
                    <td class="text-center">{!! $category->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-danger-subtle text-danger">Inactive</span>' !!}</td>
                    <td class="text-center">
                        <a href="{{ route('abs.categories.edit', $category->id) }}" class="btn btn-sm btn-encodex-edit"><i class="fas fa-edit"></i></a>
                        <x-abs::delete-button :action="route('abs.categories.destroy', $category->id)" confirm="Delete this category? Its services are kept without a category." />
                    </td>
                </tr>
            @empty
                <x-abs::empty-row colspan="5" />
            @endforelse
            </tbody>
        </table>
    </div>
    @if($categories->hasPages())<div class="mt-3">{{ $categories->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
