@extends('abs::layouts.public')

@section('title', 'Sign in')
@section('robots', 'noindex,nofollow')
@section('width', '460px')

@section('content')
<div class="abs-card p-4 p-md-5 mt-md-4">
    <div class="text-center mb-4">
        <div class="d-inline-grid rounded-circle mb-2" style="width:3.2rem;height:3.2rem;place-items:center;background:linear-gradient(135deg,#0ea5e9,#6366f1);color:#fff"><i class="fas fa-user fa-lg"></i></div>
        <h1 class="h4 fw-bold mb-1">Welcome back</h1>
        <p class="text-muted small mb-0">Sign in to see and manage your appointments.</p>
    </div>
    <form method="POST" action="{{ route('abs.account.login.store') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label small fw-semibold" for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus class="form-control form-control-lg @error('email') is-invalid @enderror">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold" for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required class="form-control form-control-lg @error('password') is-invalid @enderror">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label small" for="remember">Keep me signed in</label>
        </div>
        <button class="btn btn-primary btn-lg w-100">Sign in</button>
    </form>
    <p class="text-center small mt-4 mb-0">New here? <a href="{{ route('abs.account.register') }}" class="fw-semibold">Create an account</a></p>
    <p class="text-center small text-muted mt-2 mb-0">You can also book without an account.</p>
</div>
@endsection
