@extends('me::master')

@section('title', $branch->exists ? 'Edit Branch' : 'Add Branch')

@push('buttons')
    @component('me::components.btn.add-button', ['route' => route('abs.branches.index'), 'text' => 'All Branches', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@section('content')
<div class="card shadow mb-4">
    <div class="card-body">
        <form method="POST" action="{{ $branch->exists ? route('abs.branches.update', $branch->id) : route('abs.branches.store') }}">
            @csrf
            @if($branch->exists) @method('PUT') @endif
            <div class="row">
                <div class="col-md-6">
                    <x-abs::input name="name" label="Branch name" :value="$branch->name" required />
                    <x-abs::input name="address" label="Address" :value="$branch->address" />
                </div>
                <div class="col-md-6">
                    <x-abs::input name="phone" label="Phone" :value="$branch->phone" />
                    <x-abs::input name="email" type="email" label="E-mail" :value="$branch->email" />
                    <x-abs::input name="sort_order" type="number" label="Sort order" :value="$branch->sort_order ?? 0" min="0" />
                    <x-abs::switch name="is_active" label="Active (bookable)" :checked="$branch->is_active" />
                </div>
            </div>
            <div class="text-end"><button class="btn btn-savex"><i class="fas fa-save me-1"></i> Save</button></div>
        </form>
    </div>
</div>
@endsection
