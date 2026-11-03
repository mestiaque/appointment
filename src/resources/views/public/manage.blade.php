@extends('abs::layouts.public')

@section('title', 'Booking '.$appointment->booking_reference)
@section('robots', 'noindex,nofollow')
@section('width', '1000px')

@php
    $a = $appointment; $item = $a->items->first(); $start = $a->localStartsAt();
    $due = $a->outstandingMinor();
    $payLabel = $a->status->value === 'awaiting_payment' && $a->deposit_minor && $a->paid_minor === 0 ? 'Pay deposit '.$a->money($a->deposit_minor) : 'Pay '.$a->money($due).' online';
@endphp

@section('content')
<div class="row g-3">
    <div class="col-lg-7">
        <div class="abs-card p-4 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.05em">Booking number</div>
                    <div class="h3 fw-bold font-monospace mb-0">{{ $a->booking_reference }}</div>
                </div>
                <span class="badge abs-badge-soft fs-6 bg-{{ $a->status->color() }}-subtle text-{{ $a->status->color() }}">{{ $a->status->label() }}</span>
            </div>

            @if($a->status->value === 'awaiting_payment')
                <div class="alert alert-warning small py-2">Your slot is held until <b>{{ $a->hold_expires_at?->setTimezone($a->timezone)->format('h:i A') }}</b>. Complete the payment to secure it.</div>
            @elseif($a->status->value === 'pending')
                <div class="alert alert-info small py-2">Waiting for the business to approve your request. You will get an e-mail.</div>
            @endif

            <div class="d-flex gap-3 align-items-center p-3 rounded-4 mb-3" style="background:rgba(255,255,255,.55)">
                <div class="text-center rounded-4 px-3 py-2 text-white" style="background:linear-gradient(135deg,#0ea5e9,#6366f1);min-width:4.6rem">
                    <div class="small text-uppercase">{{ $start->format('M') }}</div><div class="h3 fw-bold mb-0 lh-1">{{ $start->format('d') }}</div><div class="small">{{ $start->format('D') }}</div>
                </div>
                <div style="min-width:0">
                    <div class="fw-bold">{{ $item?->service_name }}</div>
                    <div class="small text-muted"><i class="far fa-clock me-1"></i>{{ $start->format('h:i A') }} – {{ $a->localEndsAt()->format('h:i A') }} · {{ $item?->duration_minutes }} min</div>
                    <div class="small text-muted"><i class="fas fa-user-md me-1"></i>{{ $a->staff?->name }} · {{ $a->business->name }}</div>
                    @if($a->branch)<div class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i>{{ $a->branch->name }}, {{ $a->branch->address }}</div>@endif
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ $printUrl }}" target="_blank" rel="noopener" class="btn btn-glass"><i class="fas fa-print me-1"></i>Print slip</a>
                @if($mayCancel)
                    <form method="POST" action="{{ URL::signedRoute('abs.public.cancel', ['reference' => $a->booking_reference]) }}" onsubmit="return confirm('Cancel this booking?')">
                        @csrf<button class="btn btn-outline-danger"><i class="fas fa-ban me-1"></i>Cancel booking</button>
                    </form>
                @endif
            </div>
            <p class="small text-muted mt-3 mb-0"><i class="fas fa-info-circle me-1"></i>Keep your booking number. You can find this page again from <a href="{{ route('abs.public.find') }}">Find my booking</a>.</p>
        </div>

        @if($mayReschedule)
            <div class="abs-card p-4 mb-3">
                <h2 class="h6 fw-bold mb-3"><i class="fas fa-calendar-alt me-1 text-primary"></i> Change the time</h2>
                <livewire:abs-reschedule-form :appointment="$a" mode="customer" />
            </div>
        @endif
    </div>

    <div class="col-lg-5">
        <div class="abs-card p-4 mb-3">
            <h2 class="h6 fw-bold mb-3" id="pay"><i class="fas fa-wallet me-1 text-primary"></i> Payment</h2>
            <div class="abs-review mb-3">
                <div><span>Total</span><b>{{ $a->money($a->total_minor) }}</b></div>
                <div><span>Paid</span><b class="text-success">{{ $a->money($a->paid_minor - $a->refunded_minor) }}</b></div>
                <div class="total"><span>Amount due</span><b class="{{ $due ? 'text-danger' : 'text-success' }}">{{ $a->money($due) }}</b></div>
            </div>
            @if($due === 0)
                <div class="alert alert-success small py-2 mb-0"><i class="fas fa-check-circle me-1"></i>Fully paid. Thank you!</div>
            @elseif($mayPay)
                <div class="small fw-semibold mb-2">{{ $payLabel }} with</div>
                <div class="d-grid gap-2">
                    @foreach($gateways as $key => $gw)
                        <form method="POST" action="{{ URL::signedRoute('abs.public.pay', ['reference' => $a->booking_reference]) }}" class="m-0">
                            @csrf<input type="hidden" name="provider" value="{{ $key }}">
                            <button class="abs-gw-btn" style="--gw: {{ ['bkash' => '#e2136e', 'nagad' => '#f6921e', 'sslcommerz' => '#1e3a8a', 'stripe' => '#635bff', 'paypal' => '#003087'][$key] ?? '#6366f1' }}">
                                <i class="{{ $key === 'paypal' ? '' : 'fas' }} {{ $key === 'sslcommerz' ? 'fa-university' : ($key === 'paypal' ? 'fab fa-paypal' : ($key === 'stripe' ? 'fa-credit-card' : 'fa-mobile-alt')) }}"></i>{{ $gw->label() }}
                            </button>
                        </form>
                    @endforeach
                </div>
                <div class="small text-muted text-center mt-2">You pay on the provider's secure page — we never see your PIN or card details.</div>
                <div class="small text-muted text-center">Or pay at the counter on your visit.</div>
            @else
                <div class="d-flex gap-2 align-items-start small">
                    <i class="fas fa-store text-primary mt-1"></i>
                    <div>Pay <b>{{ $a->money($due) }}</b> at the counter on your visit (cash or card).
                        @unless($onlineAvailable)<div class="text-muted mt-1">Online payment is not available for this business yet.</div>@endunless</div>
                </div>
            @endif
        </div>

        <div class="abs-card p-4">
            <h2 class="h6 fw-bold mb-3"><i class="fas fa-file-medical me-1 text-primary"></i> Prescriptions &amp; reports</h2>
            @forelse($documents as $doc)
                <a href="{{ $doc->url(null, 30) }}" target="_blank" rel="noopener" class="abs-choice abs-tile text-decoration-none mb-2">
                    <span class="ico" style="--c:{{ $doc->isImage() ? '#8b5cf6' : '#ef4444' }}"><i class="fas {{ $doc->isImage() ? 'fa-file-image' : 'fa-file-pdf' }}"></i></span>
                    <span class="body"><span class="t">{{ $doc->title ?: $doc->original_name }}</span><span class="s">{{ $doc->created_at?->setTimezone($a->timezone)->format('d M Y') }} · {{ $doc->human_size }}</span></span>
                    <i class="fas fa-external-link-alt ms-auto text-muted small"></i>
                </a>
            @empty
                <div class="small text-muted">No documents yet. When the {{ \Illuminate\Support\Str::lower($a->staff?->title ? 'doctor' : 'business') }} shares a prescription or report, it appears here.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
