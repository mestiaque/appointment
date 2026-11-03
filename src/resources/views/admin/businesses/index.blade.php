@extends('me::master')

@section('title', 'Businesses')

@push('buttons')
    @if(auth()->user()->hasPermission('abs_business.create'))
        @component('me::components.btn.add-button', ['route' => route('abs.businesses.create'), 'text' => 'Add Business', 'class' => 'btn-encodex-create'])
        @endcomponent
    @endif
@endpush

@section('content')
<div class="card glass-card w-100">
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center">
                <tr><th>#</th><th>Name</th><th>Timezone</th><th>Currency</th><th>Staff</th><th>Services</th><th>Bookings</th><th>Status</th><th>Public page</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($businesses as $business)
                    <tr>
                        <td>{{ $businesses->firstItem() + $loop->index }}</td>
                        <td>{{ $business->name }} @if($business->id === $currentId)<span class="badge bg-primary-subtle text-primary ms-1">current</span>@endif</td>
                        <td>{{ $business->timezone }}</td>
                        <td class="text-center">{{ $business->currency }}</td>
                        <td class="text-center">{{ $business->staff_count }}</td>
                        <td class="text-center">{{ $business->services_count }}</td>
                        <td class="text-center">{{ $business->appointments_count }}</td>
                        <td class="text-center"><x-abs::status-badge :status="$business->status" /></td>
                        <td><a href="{{ route('abs.public.show', $business->slug) }}" target="_blank" rel="noopener">/{{ config('abs.public_path') }}/{{ $business->slug }}</a></td>
                        <td class="text-center">
                            <form method="POST" action="{{ route('abs.businesses.switch', $business->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-encodex-show" title="Open"><i class="fas fa-sign-in-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <x-abs::empty-row colspan="10" text="No businesses yet. Create the first one to start taking bookings." />
                @endforelse
            </tbody>
        </table>
    </div>
    @if($businesses->hasPages())<div class="mt-3">{{ $businesses->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
