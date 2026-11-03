@extends('me::master')

@section('title', $service->exists ? 'Edit Service' : 'Add Service')

@push('buttons')
    @component('me::components.btn.add-button', ['route' => route('abs.services.index'), 'text' => 'All Services', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@php $cur = $business->currency; @endphp
@section('content')
<div class="card shadow mb-4">
    <div class="card-body">
        <form method="POST" action="{{ $service->exists ? route('abs.services.update', $service->id) : route('abs.services.store') }}">
            @csrf
            @if($service->exists) @method('PUT') @endif
            <div class="row">
                <div class="col-md-6">
                    <x-abs::input name="name" label="Service name" :value="$service->name" required />
                    <x-abs::select name="category_id" label="Category" :options="$categories->pluck('name', 'id')->all()" :value="$service->category_id" placeholder="— none —" />
                    <x-abs::input name="description" type="textarea" label="Description" :value="$service->description" />
                    <x-abs::switch name="is_active" label="Active (bookable)" :checked="$service->is_active" />
                </div>
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-sm-6"><x-abs::input name="duration_minutes" type="number" label="Duration (minutes)" :value="$service->duration_minutes" min="5" max="1440" required /></div>
                        <div class="col-sm-6"><x-abs::input name="price" label="Price ({{ $cur }})" :value="\ME\Abs\Support\Currency::toDecimal((int) $service->price_minor, $cur)" required inputmode="decimal" /></div>
                        <div class="col-sm-6"><x-abs::input name="buffer_before_minutes" type="number" label="Buffer before (min)" :value="$service->buffer_before_minutes ?? 0" min="0" max="480" help="Preparation time kept free before." /></div>
                        <div class="col-sm-6"><x-abs::input name="buffer_after_minutes" type="number" label="Buffer after (min)" :value="$service->buffer_after_minutes ?? 0" min="0" max="480" help="Clean-up time kept free after." /></div>
                        <div class="col-sm-6"><x-abs::select name="deposit_type" label="Deposit" :options="['none' => 'No deposit', 'fixed' => 'Fixed amount', 'percent' => 'Percent of total']" :value="$service->deposit_type?->value ?? 'none'" required help="Used when the business requires an online deposit." /></div>
                        <div class="col-sm-6"><x-abs::input name="deposit_value" label="Deposit value" :value="$service->deposit_type?->value === 'fixed' ? \ME\Abs\Support\Currency::toDecimal($service->deposit_value, $cur) : $service->deposit_value" help="Amount in {{ $cur }}, or 1–100 for percent." /></div>
                        <div class="col-sm-6"><x-abs::input name="sort_order" type="number" label="Sort order" :value="$service->sort_order ?? 0" min="0" /></div>
                    </div>
                </div>
            </div>
            <div class="text-end"><button class="btn btn-savex"><i class="fas fa-save me-1"></i> Save</button></div>
        </form>
    </div>
</div>
@endsection
