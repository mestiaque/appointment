@php
    $abBusinesses = app(\ME\Abs\Support\BusinessAccess::class)->accessibleBusinesses(auth()->user())->limit(50)->get(['id', 'name']);
@endphp
@if(isset($currentBusiness))
    {{-- A native select, not a Bootstrap dropdown: metheme's title bar uses backdrop-filter + overflow,
         which clips any positioned menu inside it. A native select list is never clipped. --}}
    <form method="POST" action="{{ route('abs.businesses.switch', $currentBusiness->id) }}" class="d-inline-flex align-items-center m-0">
        @csrf
        <i class="fas fa-store text-muted me-1 d-none d-sm-inline" aria-hidden="true"></i>
        <select class="form-select form-select-sm py-0 abs-bar-select" aria-label="Current business"
                onchange="if (this.value === '__all') { window.location = this.dataset.allUrl; return; } this.form.action = this.selectedOptions[0].dataset.url; this.form.submit();"
                data-all-url="{{ route('abs.businesses.index') }}">
            @foreach($abBusinesses as $b)
                <option value="{{ $b->id }}" data-url="{{ route('abs.businesses.switch', $b->id) }}" @selected($b->id === $currentBusiness->id)>{{ \Illuminate\Support\Str::limit($b->name, 32) }}</option>
            @endforeach
            <option value="__all">— All businesses —</option>
        </select>
    </form>
    @once
        @push('css')
            <style>
                .abs-bar-select { height: 28px; min-width: 170px; max-width: 240px; font-size: .78rem; }
                @media (max-width: 575.98px) { .abs-bar-select { min-width: 0; max-width: 140px; } }
            </style>
        @endpush
    @endonce
@endif
