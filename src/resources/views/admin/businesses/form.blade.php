@extends('me::master')

@section('title', $business->exists ? 'Business Profile' : 'Create Business')

@push('buttons')
    @if($business->exists)
        @include('abs::admin.partials.business-bar')
    @endif
    @component('me::components.btn.add-button', ['route' => route('abs.businesses.index'), 'text' => 'All Businesses', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@section('content')
<div class="card shadow mb-4">
    <div class="card-body">
        <form action="{{ $business->exists ? route('abs.businesses.update') : route('abs.businesses.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off">
            @csrf
            @if($business->exists) @method('PUT') @endif
            <div class="row">
                <div class="col-md-6">
                    <x-abs::input name="name" label="Business name" :value="$business->name" required icon="fas fa-store" maxlength="150" />
                    <x-abs::input name="email" type="email" label="Notification e-mail" :value="$business->email" icon="fas fa-envelope" help="New bookings and cancellations are sent here (falls back to the owner's e-mail)." />
                    <x-abs::input name="phone" label="Phone" :value="$business->phone" icon="fas fa-phone" />
                    <x-abs::input name="address" label="Address" :value="$business->address" icon="fas fa-map-marker-alt" />
                    <x-abs::input name="description" type="textarea" label="Description (shown on the booking page)" :value="$business->description" />
                </div>
                <div class="col-md-6">
                    <x-abs::select name="timezone" label="Timezone" :options="array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())" :value="$business->timezone" required icon="fas fa-globe" help="Working hours and booking times use this timezone." />
                    <x-abs::input name="currency" label="Currency (ISO code)" :value="$business->currency" required icon="fas fa-coins" maxlength="3" list="abs-currencies" help="Changing it later does not convert existing prices." />
                    <datalist id="abs-currencies">@foreach(\ME\Abs\Support\Currency::commonCodes() as $code)<option value="{{ $code }}">@endforeach</datalist>
                    <x-abs::select name="status" label="Status" :options="['active' => 'Active — accepting bookings', 'inactive' => 'Inactive — booking page closed']" :value="$business->status?->value ?? 'active'" required />
                    @include('me::components.media-input', ['name' => 'logo', 'model' => $business->exists ? $business : null, 'collection' => 'logo', 'multiple' => false, 'label' => 'Logo', 'help' => 'JPG/PNG/WebP, 2 MB'])
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-between">
                <div>
                    @if($business->exists && app(\ME\Abs\Support\BusinessAccess::class)->can(auth()->user(), $business, 'abs_business.delete'))
                        <button type="submit" form="abs-delete-business" class="btn btn-sm btn-encodex-delete"><i class="fas fa-trash me-1"></i>Delete business</button>
                    @endif
                </div>
                <button type="submit" class="btn btn-savex"><i class="fas fa-save me-1"></i> Save</button>
            </div>
        </form>
        @if($business->exists)
            <form id="abs-delete-business" action="{{ route('abs.businesses.destroy') }}" method="POST" onsubmit="return confirm('Delete this business? Its booking page closes. Appointments and payments are kept.')">
                @csrf @method('DELETE')
            </form>
        @endif
    </div>
</div>
@endsection
