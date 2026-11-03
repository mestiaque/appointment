@extends('abs::layouts.public')

@section('title', 'Book an appointment')

@section('content')
<h1 class="h3 mb-4">Book an appointment</h1>
<div class="row g-3">
    @forelse($businesses as $business)
        <div class="col-md-6">
            <a href="{{ route('abs.public.show', $business->slug) }}" class="card abs-card h-100 text-decoration-none text-body">
                <div class="card-body">
                    <h2 class="h5">{{ $business->name }}</h2>
                    @if($business->address)<div class="small text-muted mb-2"><i class="fas fa-map-marker-alt me-1"></i>{{ $business->address }}</div>@endif
                    <p class="small mb-0">{{ \Illuminate\Support\Str::limit($business->description, 160) }}</p>
                </div>
            </a>
        </div>
    @empty
        <p class="text-muted">No businesses are taking bookings right now.</p>
    @endforelse
</div>
@endsection
