<div class="card glass-card w-100">
    <div class="row g-2 mb-3">
        <div class="col-md-3"><input type="search" wire:model.live.debounce.400ms="search" class="form-control form-control-sm" placeholder="Reference, name, e-mail, phone"></div>
        <div class="col-6 col-md-2">
            <select wire:model.live="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                @foreach($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select wire:model.live="paymentStatus" class="form-select form-select-sm">
                <option value="">Any payment</option>
                @foreach($paymentStatuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select wire:model.live="staffId" class="form-select form-select-sm">
                <option value="">All staff</option>
                @foreach($staffList as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-1"><input type="date" wire:model.live="from" class="form-control form-control-sm" title="From"></div>
        <div class="col-6 col-md-1"><input type="date" wire:model.live="to" class="form-control form-control-sm" title="To"></div>
        <div class="col-6 col-md-1"><button wire:click="resetFilters" class="btn btn-sm btn-encodex-clear rounded w-100"><i class="fas fa-eraser"></i></button></div>
    </div>

    <div class="table-responsive" wire:loading.class="opacity-50">
        <table class="table table-sm table-bordered table-hover table-striped table-encodex">
            <thead class="text-center"><tr><th>Reference</th><th>When ({{ $timezone }})</th><th>Customer</th><th>Service</th><th>Staff</th><th>Status</th><th>Payment</th><th></th></tr></thead>
            <tbody>
            @forelse($appointments as $a)
                <tr wire:key="appt-{{ $a->id }}">
                    <td class="text-nowrap">{{ $a->booking_reference }}</td>
                    <td class="text-nowrap">{{ $a->starts_at->setTimezone($timezone)->format('d M Y, h:i A') }}</td>
                    <td>{{ $a->customer_name }}<div class="small text-muted">{{ $a->customer_email }}</div></td>
                    <td>{{ $a->items->first()?->service_name }}</td>
                    <td>{{ $a->staff?->name }}</td>
                    <td class="text-center"><x-abs::status-badge :status="$a->status" /></td>
                    <td class="text-center"><x-abs::status-badge :status="$a->payment_status" /><div class="small">{{ $a->money($a->total_minor) }}</div></td>
                    <td class="text-center"><a href="{{ route('abs.appointments.show', $a->id) }}" class="btn btn-sm btn-encodex-show" title="Open"><i class="fas fa-eye"></i></a></td>
                </tr>
            @empty
                <x-abs::empty-row colspan="8" text="No appointments match these filters." />
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-2">{{ $appointments->links() }}</div>
</div>
