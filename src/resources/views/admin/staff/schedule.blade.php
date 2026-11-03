@extends('me::master')

@section('title', 'Hours & Time Off — '.$staff->name)

@push('buttons')
    @component('me::components.btn.add-button', ['route' => route('abs.staff.index'), 'text' => 'All Staff', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@section('content')
<div class="row">
    <div class="col-xl-8">
        <livewire:abs-schedule-editor :staff="$staff" />
    </div>
    <div class="col-xl-4">
        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-plane-departure me-1"></i> Leave & unavailable periods</h6>
            <p class="small text-muted">Times are in {{ $business->timezone }}.</p>
            @if($canEdit)
                <form method="POST" action="{{ route('abs.staff.time-off.store', $staff->id) }}" class="mb-3">
                    @csrf
                    <x-abs::input name="starts_at" type="datetime-local" label="From" required />
                    <x-abs::input name="ends_at" type="datetime-local" label="Until" required />
                    <x-abs::input name="reason" label="Reason (internal)" />
                    <button class="btn btn-sm btn-savex w-100"><i class="fas fa-plus me-1"></i> Add time off</button>
                </form>
            @endif
            <ul class="list-group list-group-flush">
                @forelse($timeOff as $off)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="small">
                            {{ $off->starts_at->setTimezone($business->timezone)->format('d M Y H:i') }} →
                            {{ $off->ends_at->setTimezone($business->timezone)->format('d M Y H:i') }}
                            @if($off->reason)<br><span class="text-muted">{{ $off->reason }}</span>@endif
                        </span>
                        @if($canEdit)<x-abs::delete-button :action="route('abs.staff.time-off.destroy', [$staff->id, $off->id])" />@endif
                    </li>
                @empty
                    <li class="list-group-item px-0 text-muted small">No upcoming time off.</li>
                @endforelse
            </ul>
        </div></div>
    </div>
</div>
@endsection
