@extends('me::master')

@section('title', $category->exists ? 'Edit Category' : 'Add Category')

@push('buttons')
    @component('me::components.btn.add-button', ['route' => route('abs.categories.index'), 'text' => 'All Categories', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@section('content')
<div class="card shadow mb-4">
    <div class="card-body">
        <form method="POST" action="{{ $category->exists ? route('abs.categories.update', $category->id) : route('abs.categories.store') }}">
            @csrf
            @if($category->exists) @method('PUT') @endif
            <div class="row">
                <div class="col-md-6"><x-abs::input name="name" label="Name" :value="$category->name" required /></div>
                <div class="col-md-3"><x-abs::input name="sort_order" type="number" label="Sort order" :value="$category->sort_order ?? 0" min="0" /></div>
                <div class="col-md-3 pt-4"><x-abs::switch name="is_active" label="Active" :checked="$category->is_active" /></div>
            </div>
            <div class="text-end"><button class="btn btn-savex"><i class="fas fa-save me-1"></i> Save</button></div>
        </form>
    </div>
</div>
@endsection
