@extends('abs::layouts.public')

@section('title', 'Create account')
@section('robots', 'noindex,nofollow')
@section('width', '520px')

@section('content')
<div class="abs-card p-4 p-md-5 mt-md-4">
    <div class="text-center mb-4">
        <h1 class="h4 fw-bold mb-1">Create your account</h1>
        <p class="text-muted small mb-0">Book faster and keep all your appointments in one place.</p>
    </div>
    <form method="POST" action="{{ route('abs.account.register.store') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label small fw-semibold" for="name">Full name</label>
            <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus class="form-control @error('name') is-invalid @enderror">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="row g-3 mb-3">
            <div class="col-sm-7">
                <label class="form-label small fw-semibold" for="email">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required class="form-control @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-5">
                <label class="form-label small fw-semibold" for="phone">Phone</label>
                <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" class="form-control @error('phone') is-invalid @enderror">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <label class="form-label small fw-semibold" for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="new-password" required class="form-control @error('password') is-invalid @enderror">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text small">At least 8 characters.</div>
            </div>
            <div class="col-sm-6">
                <label class="form-label small fw-semibold" for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required class="form-control">
            </div>
        </div>
        <button class="btn btn-primary btn-lg w-100">Create account</button>
    </form>
    <p class="text-center small mt-4 mb-0">Already have an account? <a href="{{ route('abs.account.login') }}" class="fw-semibold">Sign in</a></p>
</div>
@endsection
