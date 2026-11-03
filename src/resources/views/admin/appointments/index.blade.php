@extends('me::master')

@section('title', 'Appointments')

@push('buttons')
    @include('abs::admin.partials.business-bar')
    @if(app(\ME\Abs\Support\BusinessAccess::class)->can(auth()->user(), $currentBusiness, 'abs_appointment.create'))
        @component('me::components.btn.add-button', ['route' => route('abs.appointments.create'), 'text' => 'New Booking', 'class' => 'btn-encodex-create'])
        @endcomponent
    @endif
@endpush

@section('content')
    <livewire:abs-appointment-table />
@endsection
