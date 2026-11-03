<div>
    <div class="row g-2 align-items-end">
        <div class="col-sm-4">
            <label class="small fw-bold">New date ({{ $appointment->timezone }})</label>
            <input type="date" wire:model.live="date" class="form-control form-control-sm">
        </div>
        @if($mode === 'staff')
            <div class="col-sm-4">
                <label class="small fw-bold">Staff</label>
                <select wire:model.live="staffChoice" class="form-select form-select-sm">
                    @foreach($staffOptions as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="col-sm-4">
            <label class="small fw-bold">Reason (optional)</label>
            <input wire:model="reason" class="form-control form-control-sm" maxlength="255">
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3" wire:loading.class="opacity-50">
        @forelse($times as $t)
            <button type="button" wire:key="r-{{ $t['start'] }}" wire:click="$set('selectedSlot', '{{ $t['start'] }}')" class="btn btn-sm {{ $selectedSlot === $t['start'] ? 'btn-primary' : 'btn-outline-primary' }}">{{ $t['label'] }}</button>
        @empty
            <span class="text-muted small">No free time on this date.</span>
        @endforelse
    </div>
    @error('selectedSlot')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
    <button wire:click="save" wire:loading.attr="disabled" class="btn btn-sm btn-savex mt-3" @disabled(! $selectedSlot)>
        <i class="fas fa-calendar-check me-1"></i> Move appointment
    </button>
</div>
