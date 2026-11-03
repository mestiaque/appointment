@extends('abs::layouts.public')

@section('title', 'Profile')
@section('robots', 'noindex,nofollow')
@section('width', '760px')

@section('content')
<h1 class="h4 fw-bold mb-4">Your profile</h1>
<div class="row g-4">
    <div class="col-md-7">
        <form method="POST" action="{{ route('abs.account.profile.update') }}" class="abs-card p-4">
            @csrf @method('PUT')
            <h2 class="h6 fw-bold mb-3">Personal details</h2>
            <div class="mb-3"><label class="form-label small fw-semibold" for="name">Full name</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required class="form-control @error('name') is-invalid @enderror">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label class="form-label small fw-semibold" for="email">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required class="form-control @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-4"><label class="form-label small fw-semibold" for="phone">Phone</label>
                <input id="phone" type="tel" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control @error('phone') is-invalid @enderror">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <button class="btn btn-primary">Save changes</button>
        </form>
    </div>
    <div class="col-md-5">
        <form method="POST" action="{{ route('abs.account.password.update') }}" class="abs-card p-4">
            @csrf @method('PUT')
            <h2 class="h6 fw-bold mb-3">Change password</h2>
            <div class="mb-3"><label class="form-label small fw-semibold" for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" autocomplete="current-password" required class="form-control @error('current_password') is-invalid @enderror">
                @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label class="form-label small fw-semibold" for="password">New password</label>
                <input id="password" type="password" name="password" autocomplete="new-password" required class="form-control @error('password') is-invalid @enderror">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-4"><label class="form-label small fw-semibold" for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required class="form-control"></div>
            <button class="btn btn-glass">Update password</button>
        </form>
    </div>
</div>
@endsection
