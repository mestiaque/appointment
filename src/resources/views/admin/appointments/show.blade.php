@extends('me::master')

@section('title', 'Booking '.$appointment->booking_reference)

@push('buttons')
    @if($can['invoice'])
        <a href="{{ route('abs.appointments.invoice', $appointment->id) }}" class="btn btn-sm btn-encodex-show" target="_blank"><i class="fas fa-file-invoice me-1"></i> Invoice / receipt</a>
    @endif
    @component('me::components.btn.add-button', ['route' => route('abs.appointments.index'), 'text' => 'All Appointments', 'class' => 'btn-encodex-list'])
    @endcomponent
@endpush

@php
    $tz = $appointment->timezone;
    $money = fn ($minor) => $appointment->money($minor);
    $item = $appointment->items->first();
@endphp

@section('content')
<div class="row">
    <div class="col-xl-8">
        <div class="card shadow mb-4"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h5 class="mb-1">{{ $item?->service_name }} <small class="text-muted">· {{ $item?->duration_minutes }} min</small></h5>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-1"></i>{{ $appointment->localStartsAt()->format('l, d M Y') }}
                        <i class="fas fa-clock ms-2 me-1"></i>{{ $appointment->localStartsAt()->format('h:i A') }} – {{ $appointment->localEndsAt()->format('h:i A') }}
                        <span class="small">({{ $tz }})</span>
                    </div>
                </div>
                <div class="text-end">
                    <x-abs::status-badge :status="$appointment->status" class="fs-6" />
                    <div class="mt-1"><x-abs::status-badge :status="$appointment->payment_status" /></div>
                </div>
            </div>
            <div class="row small">
                <div class="col-md-6">
                    <p class="mb-1"><b>Customer:</b> {{ $appointment->customer_name }} @if($appointment->customer)<span class="badge bg-info-subtle text-info">registered</span>@endif</p>
                    <p class="mb-1"><b>E-mail:</b> {{ $appointment->customer_email }}</p>
                    <p class="mb-1"><b>Phone:</b> {{ $appointment->customer_phone ?: '—' }}</p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><b>Staff:</b> {{ $appointment->staff?->name }}</p>
                    <p class="mb-1"><b>Branch:</b> {{ $appointment->branch?->name ?? '—' }}</p>
                    <p class="mb-1"><b>Source:</b> {{ $appointment->source === 'admin' ? 'Staff booking' : 'Online' }} · {{ $appointment->approval_mode->label() }}</p>
                </div>
            </div>
            @if($appointment->customer_notes)
                <div class="alert alert-light border small mt-2 mb-0"><b>Customer notes:</b> {{ $appointment->customer_notes }}</div>
            @endif
            @if($appointment->cancellation_reason)
                <div class="alert alert-danger small mt-2 mb-0"><b>Reason:</b> {{ $appointment->cancellation_reason }}</div>
            @endif

            @php $visible = collect($transitions)->filter(fn ($s) => match ($s->value) {
                'confirmed', 'rejected' => $appointment->status->value === 'pending' ? $can['approve'] : $can['status'],
                'cancelled' => $can['cancel'],
                default => $can['status'],
            }); @endphp
            @if($visible->isNotEmpty())
                <hr>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($visible as $status)
                        @php ['label' => $label, 'color' => $color, 'icon' => $icon] = $status->actionMeta(); @endphp
                        <form method="POST" action="{{ route('abs.appointments.transition', $appointment->id) }}" class="d-flex gap-1"
                              @if(in_array($status->value, ['rejected', 'cancelled'])) onsubmit="const r = prompt('Reason (optional, shown to the customer):'); if (r === null) return false; this.reason.value = r;" @endif>
                            @csrf
                            <input type="hidden" name="status" value="{{ $status->value }}">
                            <input type="hidden" name="reason" value="">
                            <button class="btn btn-sm btn-outline-{{ $color }}"><i class="fas fa-{{ $icon }} me-1"></i>{{ $label }}</button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div></div>

        @if($can['reschedule'] && in_array($appointment->status->value, ['pending', 'confirmed']))
            <div class="card shadow mb-4"><div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-calendar-alt me-1"></i> Reschedule</h6>
                <livewire:abs-reschedule-form :appointment="$appointment" mode="staff" />
            </div></div>
        @endif

        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-money-bill-wave me-1"></i> Payments</h6>
            <div class="row text-center mb-3 g-2">
                <div class="col"><div class="border rounded p-2"><div class="small text-muted">Total</div><b>{{ $money($appointment->total_minor) }}</b></div></div>
                <div class="col"><div class="border rounded p-2"><div class="small text-muted">Paid</div><b>{{ $money($appointment->paid_minor) }}</b></div></div>
                <div class="col"><div class="border rounded p-2"><div class="small text-muted">Refunded</div><b>{{ $money($appointment->refunded_minor) }}</b></div></div>
                <div class="col"><div class="border rounded p-2"><div class="small text-muted">Outstanding</div><b class="{{ $appointment->outstandingMinor() ? 'text-danger' : 'text-success' }}">{{ $money($appointment->outstandingMinor()) }}</b></div></div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-encodex">
                    <thead class="text-center"><tr><th>#</th><th>Method</th><th>Purpose</th><th>Amount</th><th>Status</th><th>Date</th><th>Refunds</th></tr></thead>
                    <tbody>
                    @forelse($appointment->payments as $payment)
                        <tr>
                            <td>{{ $payment->id }}</td>
                            <td>{{ ucfirst($payment->provider) }}@if($payment->recordedBy)<div class="small text-muted">by {{ $payment->recordedBy->name }}</div>@endif</td>
                            <td>{{ ucfirst($payment->purpose->value) }}</td>
                            <td class="text-end">{{ $money($payment->amount_minor) }}</td>
                            <td class="text-center"><x-abs::status-badge :status="$payment->status" />@if($payment->failure_reason)<div class="small text-muted">{{ $payment->failure_reason }}</div>@endif</td>
                            <td class="small">{{ ($payment->paid_at ?? $payment->created_at)?->setTimezone($tz)->format('d M Y H:i') }}</td>
                            <td class="small">
                                @foreach($payment->refunds as $refund)
                                    <div>{{ $money($refund->amount_minor) }} <x-abs::status-badge :status="$refund->status" /></div>
                                @endforeach
                                @if($can['refund'] && $payment->refundableMinor() > 0)
                                    <form method="POST" id="abs-refund-{{ $payment->id }}" action="{{ route('abs.payments.refund', $payment->id) }}" class="d-flex gap-1 mt-1" onsubmit="return confirm('Refund this amount?')">
                                        @csrf
                                        <input name="amount" class="form-control form-control-sm" style="max-width:110px" value="{{ \ME\Abs\Support\Currency::toDecimal($payment->refundableMinor(), $payment->currency) }}" inputmode="decimal">
                                        <input name="reason" class="form-control form-control-sm" placeholder="Reason">
                                        <button class="btn btn-sm btn-outline-danger" title="Refund"><i class="fas fa-undo"></i></button>
                                    </form>
                                    @unless(in_array($payment->provider, ['cash', 'manual']))
                                        <label class="small text-muted d-block mt-1"><input type="checkbox" form="abs-refund-{{ $payment->id }}" name="outside_system" value="1"> already refunded from the {{ ucfirst($payment->provider) }} merchant portal</label>
                                    @endunless
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-abs::empty-row colspan="7" text="No payments yet." />
                    @endforelse
                    </tbody>
                </table>
            </div>
            @error('refund_amount')<div class="text-danger small">{{ $message }}</div>@enderror
            @if($can['record'] && $appointment->outstandingMinor() > 0 && ! in_array($appointment->status->value, ['cancelled', 'rejected', 'expired']))
                <form method="POST" action="{{ route('abs.payments.store', $appointment->id) }}" class="row g-2 align-items-end mt-2">
                    @csrf
                    <div class="col-sm-3"><label class="small fw-bold">Amount ({{ $appointment->currency }})</label><input name="amount" inputmode="decimal" class="form-control form-control-sm @error('amount') is-invalid @enderror" value="{{ old('amount', \ME\Abs\Support\Currency::toDecimal($appointment->outstandingMinor(), $appointment->currency)) }}"></div>
                    <div class="col-sm-3"><label class="small fw-bold">Method</label><select name="method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="manual">Other / bank transfer</option></select></div>
                    <div class="col-sm-4"><label class="small fw-bold">Note</label><input name="notes" class="form-control form-control-sm" maxlength="255"></div>
                    <div class="col-sm-2"><button class="btn btn-sm btn-savex w-100"><i class="fas fa-plus me-1"></i>Record</button></div>
                    @error('amount')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
                </form>
            @endif
        </div></div>
    </div>

    <div class="col-xl-4">
        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-receipt me-1"></i> Price</h6>
            <table class="table table-sm mb-0">
                <tr><td>{{ $item?->service_name }} × {{ $item?->quantity }}</td><td class="text-end">{{ $money($appointment->subtotal_minor) }}</td></tr>
                @if($appointment->discount_minor)<tr><td>Discount</td><td class="text-end">−{{ $money($appointment->discount_minor) }}</td></tr>@endif
                @if($appointment->tax_minor)<tr><td>Tax</td><td class="text-end">{{ $money($appointment->tax_minor) }}</td></tr>@endif
                <tr class="fw-bold"><td>Total</td><td class="text-end">{{ $money($appointment->total_minor) }}</td></tr>
                @if($appointment->deposit_minor)<tr><td class="small text-muted">Deposit required</td><td class="text-end small">{{ $money($appointment->deposit_minor) }}</td></tr>@endif
            </table>
            @if($can['edit'] && ! in_array($appointment->status->value, ['cancelled', 'rejected', 'expired']))
                <form method="POST" action="{{ route('abs.appointments.discount', $appointment->id) }}" class="d-flex gap-1 mt-3">
                    @csrf @method('PUT')
                    <input name="discount" inputmode="decimal" class="form-control form-control-sm @error('discount') is-invalid @enderror" placeholder="Discount ({{ $appointment->currency }})" value="{{ old('discount', $appointment->discount_minor ? \ME\Abs\Support\Currency::toDecimal($appointment->discount_minor, $appointment->currency) : '') }}">
                    <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="fas fa-tag me-1"></i>Apply</button>
                </form>
                @error('discount')<div class="text-danger small">{{ $message }}</div>@enderror
            @endif
        </div></div>

        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-1"><i class="fas fa-file-medical me-1"></i> Prescriptions &amp; reports</h6>
            <p class="small text-muted">Shared with the customer on their booking page (after phone verification). Stored privately.</p>
            <ul class="list-unstyled small mb-2">
                @forelse($appointment->getMedia('documents') as $doc)
                    <li class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas {{ $doc->isImage() ? 'fa-file-image text-primary' : 'fa-file-pdf text-danger' }}"></i>
                        <a href="{{ $doc->url(null, 30) }}" target="_blank" rel="noopener" class="text-truncate">{{ $doc->title ?: $doc->original_name }}</a>
                        <span class="text-muted ms-auto text-nowrap">{{ $doc->human_size }}</span>
                        @if($can['edit'])<x-abs::delete-button :action="route('abs.appointments.documents.destroy', [$appointment->id, $doc->id])" confirm="Remove this document? The customer will no longer see it." />@endif
                    </li>
                @empty
                    <li class="text-muted">No documents yet.</li>
                @endforelse
            </ul>
            @if($can['edit'])
                <form method="POST" action="{{ route('abs.appointments.documents.store', $appointment->id) }}" enctype="multipart/form-data" class="d-grid gap-2">
                    @csrf
                    <input name="title" class="form-control form-control-sm @error('title') is-invalid @enderror" placeholder="Title, e.g. Prescription, Blood report" value="{{ old('title') }}" maxlength="120">
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control form-control-sm @error('document') is-invalid @enderror">
                    @error('title')<div class="text-danger small">{{ $message }}</div>@enderror
                    @error('document')<div class="text-danger small">{{ $message }}</div>@enderror
                    <button class="btn btn-sm btn-savex"><i class="fas fa-upload me-1"></i>Upload (PDF/image, max 10 MB)</button>
                </form>
            @endif
        </div></div>

        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-sticky-note me-1"></i> Internal notes</h6>
            <form method="POST" action="{{ route('abs.appointments.notes', $appointment->id) }}">
                @csrf @method('PUT')
                <textarea name="internal_notes" rows="3" class="form-control form-control-sm" @disabled(! $can['edit'])>{{ old('internal_notes', $appointment->internal_notes) }}</textarea>
                @if($can['edit'])<button class="btn btn-sm btn-savex mt-2"><i class="fas fa-save me-1"></i>Save</button>@endif
            </form>
        </div></div>

        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-user-clock me-1"></i> Customer's other bookings</h6>
            <ul class="list-unstyled small mb-0">
                @forelse($history as $other)
                    <li class="mb-1 d-flex justify-content-between gap-2">
                        <a href="{{ route('abs.appointments.show', $other->id) }}">{{ $other->starts_at->setTimezone($other->timezone)->format('d M Y H:i') }} · {{ $other->items->first()?->service_name }}</a>
                        <x-abs::status-badge :status="$other->status" />
                    </li>
                @empty
                    <li class="text-muted">First booking with this e-mail.</li>
                @endforelse
            </ul>
        </div></div>

        <div class="card shadow mb-4"><div class="card-body">
            <h6 class="fw-bold mb-3"><i class="fas fa-history me-1"></i> History</h6>
            <ul class="list-unstyled small mb-0">
                @foreach($appointment->statusHistories as $h)
                    <li class="mb-2">
                        <span class="text-muted">{{ $h->created_at->setTimezone($tz)->format('d M H:i') }}</span>
                        {{ $h->from_status?->label() ?? 'Created' }} → <b>{{ $h->to_status->label() }}</b>
                        <span class="text-muted">· {{ $h->actor?->name ?? ucfirst($h->actor_type->value) }}</span>
                        @if($h->reason)<div class="text-muted fst-italic">{{ $h->reason }}</div>@endif
                    </li>
                @endforeach
                @foreach($appointment->rescheduleHistories as $r)
                    <li class="mb-2">
                        <span class="text-muted">{{ $r->created_at->setTimezone($tz)->format('d M H:i') }}</span>
                        Rescheduled {{ $r->old_starts_at->setTimezone($tz)->format('d M H:i') }} → <b>{{ $r->new_starts_at->setTimezone($tz)->format('d M H:i') }}</b>
                        <span class="text-muted">· {{ $r->actor?->name ?? ucfirst($r->actor_type->value) }}</span>
                    </li>
                @endforeach
            </ul>
        </div></div>
    </div>
</div>
@endsection
