@extends('abs::layouts.public')

@section('title', 'Find my booking')
@section('robots', 'noindex,nofollow')
@section('width', '520px')

@section('content')
<div class="abs-card p-4 p-md-5 mt-md-3">
    <div class="text-center mb-4">
        <span class="d-inline-grid rounded-circle mb-2 text-white" style="width:3.2rem;height:3.2rem;place-items:center;background:linear-gradient(135deg,#0ea5e9,#6366f1)"><i class="fas fa-search fa-lg"></i></span>
        <h1 class="h4 fw-bold mb-1">Find my booking</h1>
        <p class="text-muted small mb-0">See your appointment, prescriptions or reports, and pay any balance.</p>
    </div>

    @if($pending)
        <form method="POST" action="{{ route('abs.public.find.verify') }}" novalidate>
            @csrf
            <div class="alert alert-info small py-2 mb-3"><i class="fas fa-sms me-1"></i>Enter the 6-digit code sent to <b>{{ $pending['phone'] }}</b> for booking <b>{{ $pending['reference'] }}</b>.</div>
            <label class="form-label small fw-semibold" for="code">Verification code</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                   class="form-control form-control-lg text-center fw-bold @error('code') is-invalid @enderror" style="letter-spacing:.5em">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <button class="btn btn-primary btn-lg w-100 mt-3"><i class="fas fa-unlock me-1"></i>Verify and open</button>
        </form>
        <form method="POST" action="{{ route('abs.public.find.restart') }}" class="text-center mt-3">@csrf
            <button class="btn btn-link btn-sm text-muted">Use a different booking</button>
        </form>
    @else
        <form method="POST" action="{{ route('abs.public.find.lookup') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="reference">Booking number</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-0 fw-semibold text-muted">BK-</span>
                    <input id="reference" name="reference" value="{{ old('reference') }}" placeholder="7Q4M9XR2" autocomplete="off" autocapitalize="characters"
                           class="form-control form-control-lg font-monospace text-uppercase @error('reference') is-invalid @enderror">
                </div>
                @error('reference')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="form-text small">It is on your confirmation e-mail and booking slip.</div>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-semibold" for="phone">Phone number used for the booking</label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" class="form-control form-control-lg @error('phone') is-invalid @enderror">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary btn-lg w-100"><i class="fas fa-search me-1"></i>{{ $smsEnabled ? 'Send verification code' : 'Find booking' }}</button>
        </form>
        <p class="small text-muted text-center mt-3 mb-0"><i class="fas fa-shield-alt me-1 text-success"></i>
            {{ $smsEnabled ? 'We send a one-time code to your phone to confirm it is you.' : 'The phone number must match the one on the booking.' }}</p>
    @endif
</div>
@endsection
