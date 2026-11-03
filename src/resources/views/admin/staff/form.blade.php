@extends('me::master')

@section('title', $staff->exists ? 'Edit Staff' : 'Add Staff')

@push('buttons')
    @component('me::components.btn.add-button', ['route' => route('abs.staff.index'), 'text' => 'All Staff', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@php
    $cur = $business->currency;
    $assigned = $staff->exists ? $staff->services->keyBy('id') : collect();
    $assignedBranches = $staff->exists ? $staff->branches->pluck('id')->all() : [];
@endphp
@section('content')
<form method="POST" action="{{ $staff->exists ? route('abs.staff.update', $staff->id) : route('abs.staff.store') }}">
    @csrf
    @if($staff->exists) @method('PUT') @endif
    <div class="row">
        <div class="col-lg-5">
            <div class="card shadow mb-4"><div class="card-body">
                <x-abs::input name="name" label="Name" :value="$staff->name" required />
                <x-abs::input name="title" label="Title / role shown to customers" :value="$staff->title" placeholder="e.g. Senior stylist, Consultant" />
                <x-abs::input name="email" type="email" label="E-mail" :value="$staff->email" />
                <x-abs::input name="phone" label="Phone" :value="$staff->phone" />
                <x-abs::select name="user_id" label="Login account (optional)" :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->email])->all()" :value="$staff->user_id" placeholder="— no login —" help="Team members of this business. A staff-role member linked here sees only their own appointments." />
                <x-abs::input name="bio" type="textarea" label="Short bio" :value="$staff->bio" />
                <x-abs::input name="sort_order" type="number" label="Sort order" :value="$staff->sort_order ?? 0" min="0" />
                <x-abs::switch name="is_active" label="Active" :checked="$staff->is_active" />
                <x-abs::switch name="accepts_bookings" label="Accepts online bookings" :checked="$staff->accepts_bookings" />
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow mb-4"><div class="card-body">
                <h6 class="fw-bold mb-2"><i class="fas fa-concierge-bell me-1"></i> Services offered</h6>
                <p class="small text-muted">Leave price or duration empty to use the service defaults.</p>
                @error('services')<div class="text-danger small">{{ $message }}</div>@enderror
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-encodex align-middle">
                        <thead class="text-center"><tr><th></th><th>Service</th><th>Default</th><th>Price override ({{ $cur }})</th><th>Duration override</th></tr></thead>
                        <tbody>
                        @forelse($services as $i => $service)
                            @php $pivot = $assigned->get($service->id)?->pivot; @endphp
                            <tr>
                                <td class="text-center">
                                    <input type="hidden" name="services[{{ $i }}][id]" value="{{ $service->id }}">
                                    <input type="checkbox" class="form-check-input" name="services[{{ $i }}][enabled]" value="1" @checked(old("services.$i.enabled", $assigned->has($service->id)))>
                                </td>
                                <td>{{ $service->name }}</td>
                                <td class="small text-muted text-nowrap">{{ \ME\Abs\Support\Currency::format($service->price_minor, $cur) }}, {{ $service->duration_minutes }} min</td>
                                <td><input type="text" inputmode="decimal" class="form-control form-control-sm @error("services.$i.price") is-invalid @enderror" name="services[{{ $i }}][price]" value="{{ old("services.$i.price", $pivot?->price_minor !== null ? \ME\Abs\Support\Currency::toDecimal($pivot->price_minor, $cur) : '') }}"></td>
                                <td><input type="number" min="5" max="1440" class="form-control form-control-sm" name="services[{{ $i }}][duration_minutes]" value="{{ old("services.$i.duration_minutes", $pivot?->duration_minutes) }}"></td>
                            </tr>
                        @empty
                            <x-abs::empty-row colspan="5" text="Create services first." />
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div></div>
            @if($branches->isNotEmpty())
                <div class="card shadow mb-4"><div class="card-body">
                    <h6 class="fw-bold mb-2"><i class="fas fa-code-branch me-1"></i> Branches</h6>
                    <p class="small text-muted">None selected = works at every branch.</p>
                    @foreach($branches as $branch)
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="branches[]" value="{{ $branch->id }}" id="branch{{ $branch->id }}" @checked(in_array($branch->id, old('branches', $assignedBranches)))>
                            <label class="form-check-label" for="branch{{ $branch->id }}">{{ $branch->name }}</label>
                        </div>
                    @endforeach
                </div></div>
            @endif
        </div>
    </div>
    <div class="text-end mb-4"><button class="btn btn-savex"><i class="fas fa-save me-1"></i> Save</button></div>
</form>
@endsection
