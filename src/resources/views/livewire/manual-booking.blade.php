<div class="row">
    <div class="col-lg-7">
        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-concierge-bell me-1"></i> Service & time</h6>
            <div class="row">
                @if($branches->isNotEmpty())
                    <div class="col-md-6 mb-3">
                        <label class="small fw-bold">Branch</label>
                        <select wire:model.live="branchId" class="form-select form-select-sm">
                            <option value="">Any branch</option>
                            @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-6 mb-3">
                    <label class="small fw-bold">Service <span class="text-danger">*</span></label>
                    <select wire:model.live="serviceId" class="form-select form-select-sm @error('serviceId') is-invalid @enderror">
                        <option value="">— choose —</option>
                        @foreach($services as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->duration_minutes }} min, {{ \ME\Abs\Support\Currency::format($s->price_minor, $business->currency) }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="small fw-bold">Staff</label>
                    <select wire:model.live="staffChoice" class="form-select form-select-sm" @disabled(! $serviceId)>
                        <option value="any">Any available</option>
                        @foreach($staffOptions as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="small fw-bold">Date ({{ $business->timezone }})</label>
                    <input type="date" wire:model.live="date" class="form-control form-control-sm">
                </div>
            </div>
            <label class="small fw-bold d-block">Time <span class="text-danger">*</span></label>
            <div class="d-flex flex-wrap gap-2" wire:loading.class="opacity-50">
                @forelse($times as $t)
                    <button type="button" wire:key="t-{{ $t['start'] }}" wire:click="$set('selectedSlot', '{{ $t['start'] }}')" class="btn btn-sm {{ $selectedSlot === $t['start'] ? 'btn-primary' : 'btn-outline-primary' }}">{{ $t['label'] }}</button>
                @empty
                    <span class="text-muted small">
                        @if(! $serviceId)
                            Choose a service first.
                        @elseif(! $hasOffers)
                            No active staff member offers this service{{ $branchId ? ' at this branch' : '' }}. Change the branch or staff, or assign the service on the Staff page.
                        @elseif($nextDate)
                            No free time on this date (no working hours, a holiday, or fully booked).
                            Next free day: <a href="#" wire:click.prevent="useDate('{{ $nextDate }}')">{{ \Carbon\CarbonImmutable::parse($nextDate)->format('D, d M Y') }}</a>
                        @else
                            No free time on this date, and none in the next 60 days. Check the staff working hours.
                        @endif
                    </span>
                @endforelse
            </div>
            @error('selectedSlot')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
            <p class="small text-muted mt-2 mb-0">Staff bookings ignore the online notice/horizon rules but never double-book.</p>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-user me-1"></i> Customer</h6>
            <div class="mb-2"><label class="small fw-bold">Name *</label><input wire:model="name" class="form-control form-control-sm @error('name') is-invalid @enderror">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-2"><label class="small fw-bold">E-mail *</label><input type="email" wire:model="email" class="form-control form-control-sm @error('email') is-invalid @enderror">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="text-muted">If a registered user has this e-mail, the booking is linked to their account.</small></div>
            <div class="mb-2"><label class="small fw-bold">Phone</label><input wire:model="phone" class="form-control form-control-sm"></div>
            <div class="mb-2"><label class="small fw-bold">Customer notes</label><textarea wire:model="notes" rows="2" class="form-control form-control-sm"></textarea></div>
            <div class="mb-3"><label class="small fw-bold">Internal notes</label><textarea wire:model="internalNotes" rows="2" class="form-control form-control-sm"></textarea></div>
            <button wire:click="save" wire:loading.attr="disabled" class="btn btn-savex w-100">
                <span wire:loading.remove wire:target="save"><i class="fas fa-save me-1"></i> Create booking</span>
                <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin me-1"></i> Saving…</span>
            </button>
        </div></div>
    </div>
</div>
