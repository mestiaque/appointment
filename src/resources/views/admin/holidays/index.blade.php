@extends('me::master')

@section('title', 'Holidays & Closed Days')

@push('buttons')
    @include('abs::admin.partials.business-bar')
@endpush

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card glass-card w-100">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover table-striped table-encodex">
                    <thead class="text-center"><tr><th>Date</th><th>Name</th><th>Branch</th><th></th></tr></thead>
                    <tbody>
                    @forelse($holidays as $holiday)
                        <tr>
                            <td class="text-center">{{ $holiday->date->format('D, d M Y') }}</td>
                            <td>{{ $holiday->name }}</td>
                            <td>{{ $holiday->branch?->name ?? 'All branches' }}</td>
                            <td class="text-center">@if($canEdit)<x-abs::delete-button :action="route('abs.holidays.destroy', $holiday->id)" />@endif</td>
                        </tr>
                    @empty
                        <x-abs::empty-row colspan="4" />
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($holidays->hasPages())<div class="mt-3">{{ $holidays->links('pagination::bootstrap-5') }}</div>@endif
        </div>
    </div>
    @if($canEdit)
    <div class="col-lg-4">
        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-plus me-1"></i> Add closed day</h6>
            <form method="POST" action="{{ route('abs.holidays.store') }}">
                @csrf
                <x-abs::input name="date" type="date" label="Date" required />
                <x-abs::input name="name" label="Name" required placeholder="e.g. Eid holiday" />
                <x-abs::select name="branch_id" label="Branch" :options="$branches->pluck('name', 'id')->all()" placeholder="All branches" />
                <button class="btn btn-sm btn-savex w-100"><i class="fas fa-save me-1"></i> Save</button>
            </form>
            <p class="small text-muted mt-3 mb-0">No booking can start or run on a closed day (whole local date). Existing bookings are not cancelled automatically.</p>
        </div></div>
    </div>
    @endif
</div>
@endsection
