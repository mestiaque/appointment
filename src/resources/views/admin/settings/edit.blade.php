@extends('me::master')

@section('title', 'Booking Settings')

@push('buttons')
    @include('abs::admin.partials.business-bar')
@endpush

@section('content')
<form method="POST" action="{{ route('abs.settings.update') }}">
    @csrf @method('PUT')
    <fieldset @disabled(! $canEdit)>
    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow mb-4"><div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-check-circle me-1"></i> Confirmation</h6>
                <x-abs::select name="approval_mode" label="Approval mode" :options="collect(\ME\Abs\Enums\ApprovalMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()" :value="$settings->approval_mode->value" required />
                <x-abs::switch name="pending_blocks_slot" label="Pending requests hold their slot" :checked="$settings->pending_blocks_slot" help="On: nobody else can book a time while a request waits for approval. Off: the slot stays open until approved (the first approval wins; a later approval of a clashing request fails)." />
                <x-abs::switch name="allow_guest_booking" label="Allow guest booking (no account needed)" :checked="$settings->allow_guest_booking" />
                <x-abs::switch name="require_customer_phone" label="Phone number required" :checked="$settings->require_customer_phone" />
            </div></div>
            <div class="card shadow mb-4"><div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-clock me-1"></i> Booking window</h6>
                <div class="row">
                    <div class="col-sm-6"><x-abs::input name="min_notice_minutes" type="number" label="Minimum notice (minutes)" :value="$settings->min_notice_minutes" min="0" required help="How soon before the start a customer may still book." /></div>
                    <div class="col-sm-6"><x-abs::input name="max_days_ahead" type="number" label="Book up to (days ahead)" :value="$settings->max_days_ahead" min="1" max="730" required /></div>
                    <div class="col-sm-6"><x-abs::select name="slot_interval_minutes" label="Slot interval" :options="collect([5,10,15,20,30,45,60,90,120])->mapWithKeys(fn ($m) => [$m => $m.' minutes'])->all()" :value="$settings->slot_interval_minutes" required /></div>
                    <div class="col-sm-6"><x-abs::input name="reminder_minutes_before" type="number" label="Reminder before start (minutes)" :value="$settings->reminder_minutes_before" min="0" required help="0 = no reminders." /></div>
                </div>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow mb-4"><div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-ban me-1"></i> Customer changes</h6>
                <x-abs::switch name="allow_customer_cancellation" label="Customers may cancel online" :checked="$settings->allow_customer_cancellation" />
                <x-abs::input name="cancellation_notice_minutes" type="number" label="…until this many minutes before the start" :value="$settings->cancellation_notice_minutes" min="0" required />
                <x-abs::switch name="allow_customer_reschedule" label="Customers may reschedule online" :checked="$settings->allow_customer_reschedule" />
                <x-abs::input name="reschedule_notice_minutes" type="number" label="…until this many minutes before the start" :value="$settings->reschedule_notice_minutes" min="0" required />
            </div></div>
            <div class="card shadow mb-4"><div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-money-bill me-1"></i> Payment & tax</h6>
                @unless($onlineAvailable)
                    <div class="alert alert-warning py-2 small">No online gateway is enabled, so only “Pay later / cash” can be selected. Set up bKash, Nagad or SSLCommerz on the <a href="{{ route('abs.gateways.index') }}">Payment Gateways</a> page.</div>
                @endunless
                <x-abs::select name="payment_mode" label="Payment mode" :options="collect(\ME\Abs\Enums\PaymentMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()" :value="$settings->payment_mode->value" required help="Deposit amounts are set per service." />
                <x-abs::input name="hold_minutes" type="number" label="Unpaid hold (minutes)" :value="$settings->hold_minutes" min="30" max="1440" required help="How long an unpaid online booking blocks its slot (Stripe needs at least 30)." />
                <x-abs::input name="tax_rate_percent" label="Tax rate (%)" :value="\ME\Abs\Support\Currency::toDecimal($settings->tax_rate_bps, 'USD')" required help="Added on top of the service price. 0 = no tax." />
            </div></div>
        </div>
    </div>
    </fieldset>
    @if($canEdit)<div class="text-end mb-4"><button class="btn btn-savex"><i class="fas fa-save me-1"></i> Save settings</button></div>@endif
</form>
@endsection
