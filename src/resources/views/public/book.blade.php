@extends('abs::layouts.public')

@section('title', 'Book — '.$business->name)
@section('width', '1140px')

@section('content')
<div class="abs-card p-3 p-md-4 mb-3 d-flex align-items-center gap-3">
    @if($logo = $business->mediaUrl('logo', 'thumb'))
        <img src="{{ $logo }}" alt="" class="rounded-4" style="height:56px;width:56px;object-fit:cover;flex:none">
    @else
        <span class="d-grid rounded-4 text-white fw-bold fs-4" style="height:56px;width:56px;flex:none;place-items:center;background:linear-gradient(135deg,#0ea5e9,#6366f1)">{{ mb_substr($business->name, 0, 1) }}</span>
    @endif
    <div class="flex-grow-1" style="min-width:0">
        <h1 class="h5 fw-bold mb-1">{{ $business->name }}</h1>
        <div class="small text-muted d-flex flex-wrap column-gap-3">
            @if($business->address)<span><i class="fas fa-map-marker-alt me-1"></i>{{ $business->address }}</span>@endif
            @if($business->phone)<a href="tel:{{ $business->phone }}" class="text-muted text-decoration-none d-none d-sm-inline"><i class="fas fa-phone me-1"></i>{{ $business->phone }}</a>@endif
        </div>
        @if($business->description)<div class="small text-muted mt-1 d-none d-md-block">{{ \Illuminate\Support\Str::limit($business->description, 160) }}</div>@endif
    </div>
</div>
<livewire:abs-booking-wizard :business="$business" />
@endsection
