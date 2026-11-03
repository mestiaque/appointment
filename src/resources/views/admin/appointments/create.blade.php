@extends('me::master')

@section('title', 'New Booking')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @component('me::components.btn.add-button', ['route' => route('abs.appointments.index'), 'text' => 'All Appointments', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@section('content')
    <livewire:abs-manual-booking />
@endsection
