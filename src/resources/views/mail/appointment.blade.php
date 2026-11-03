@extends('me::mail.master')

@section('title', $copy['subject'])
@section('preheader', $copy['intro'])

@php $item = $appointment->items->first(); @endphp
@section('content')
    <p>{{ $audience === 'customer' ? 'Hello '.$appointment->customer_name.',' : 'Hello,' }}</p>
    <p>{{ $copy['intro'] }}</p>
    <table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;border:1px solid #e5e7eb;">
        <tr><td style="color:#6b7280;width:38%;">Reference</td><td><b>{{ $appointment->booking_reference }}</b></td></tr>
        <tr><td style="color:#6b7280;">Business</td><td>{{ $appointment->business->name }}</td></tr>
        <tr><td style="color:#6b7280;">Service</td><td>{{ $item?->service_name }} ({{ $item?->duration_minutes }} min)</td></tr>
        <tr><td style="color:#6b7280;">With</td><td>{{ $appointment->staff?->name }}</td></tr>
        <tr><td style="color:#6b7280;">When</td><td>{{ $appointment->localStartsAt()->format('l, d M Y, h:i A') }} ({{ $appointment->timezone }})</td></tr>
        @if($appointment->branch)<tr><td style="color:#6b7280;">Where</td><td>{{ $appointment->branch->name }} — {{ $appointment->branch->address }}</td></tr>@endif
        <tr><td style="color:#6b7280;">Total</td><td>{{ $appointment->money($appointment->total_minor) }} · {{ $appointment->payment_status->label() }}</td></tr>
        @if($audience === 'business')
            <tr><td style="color:#6b7280;">Customer</td><td>{{ $appointment->customer_name }}, {{ $appointment->customer_email }} {{ $appointment->customer_phone }}</td></tr>
        @endif
    </table>
    @if($copy['show_manage_link'])
        @include('me::mail.partials.button', ['url' => $appointment->manageUrl(), 'text' => 'View or change booking'])
    @elseif($audience === 'business')
        @include('me::mail.partials.button', ['url' => route('abs.appointments.show', $appointment->getKey()), 'text' => 'Open in admin'])
    @endif
@endsection
