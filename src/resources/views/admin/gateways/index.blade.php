@extends('me::master')

@section('title', 'Payment Gateways')

@push('buttons')
    @include('abs::admin.partials.business-bar')
@endpush

@php
    $meta = [
        'bkash' => ['#e2136e', 'fa-mobile-alt', 'bKash Tokenized Checkout. Get credentials from bKash merchant onboarding (sandbox credentials from the bKash developer portal).'],
        'nagad' => ['#f6921e', 'fa-mobile-alt', 'Nagad Payment Gateway (check-out API). Needs merchant ID, account number and the RSA key pair exchanged with Nagad.'],
        'sslcommerz' => ['#1e3a8a', 'fa-university', 'Cards, mobile banking and internet banking through SSLCommerz. Sandbox store: developer.sslcommerz.com.'],
        'stripe' => ['#635bff', 'fa-credit-card', 'International cards via Stripe Checkout.'],
        'paypal' => ['#003087', 'fab fa-paypal', 'PayPal Checkout.'],
    ];
    $urls = [
        'sslcommerz' => ['IPN URL (set in SSLCommerz merchant panel)' => route('abs.webhooks', ['provider' => 'sslcommerz', 'business' => $business->id])],
        'stripe' => ['Webhook URL' => route('abs.webhooks', ['provider' => 'stripe', 'business' => $business->id])],
        'paypal' => ['Webhook URL' => route('abs.webhooks', ['provider' => 'paypal', 'business' => $business->id])],
    ];
@endphp

@section('content')
<div class="alert alert-info small">
    <i class="fas fa-info-circle me-1"></i>
    Payments go to <b>{{ $business->name }}</b>'s own merchant accounts. Keys are encrypted with the app key and never shown again — leave a secret field empty to keep the saved value.
    After enabling a gateway, choose an online payment mode on <a href="{{ route('abs.settings.edit') }}">Booking Settings</a>.
    Business currency: <b>{{ $business->currency }}</b> (bKash and Nagad accept BDT only).
</div>

<div class="row g-3">
    @foreach($providers as $name => $gateway)
        @php
            $row = $settings->get($name);
            [$color, $icon, $about] = $meta[$name] ?? ['#64748b', 'fa-credit-card', ''];
            $saved = (array) ($row?->credentials ?? []);
            $isLive = in_array($name, $active, true);
        @endphp
        <div class="col-xl-6">
            <form method="POST" action="{{ route('abs.gateways.update', $name) }}" class="card shadow h-100" autocomplete="off">
                @csrf @method('PUT')
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-2">
                        <span class="d-grid rounded-3 text-white flex-shrink-0" style="width:2.6rem;height:2.6rem;place-items:center;background:{{ $color }}"><i class="{{ str_starts_with($icon, 'fab ') ? $icon : 'fas '.$icon }}"></i></span>
                        <div class="flex-grow-1">
                            <h6 class="fw-bold mb-0">{{ $gateway->label() }}</h6>
                            <div class="small text-muted">{{ $about }}</div>
                        </div>
                        @if($isLive)
                            <span class="badge bg-success-subtle text-success">Active{{ $row?->is_sandbox ? ' · sandbox' : ' · live' }}</span>
                        @elseif($row?->is_enabled)
                            <span class="badge bg-warning-subtle text-warning">Incomplete</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">Off</span>
                        @endif
                    </div>

                    <fieldset @disabled(! $canEdit)>
                    <div class="d-flex gap-4 mb-3">
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="en-{{ $name }}" @checked($row?->is_enabled)>
                            <label class="form-check-label small fw-semibold" for="en-{{ $name }}">Enabled</label>
                        </div>
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_sandbox" value="0">
                            <input class="form-check-input" type="checkbox" name="is_sandbox" value="1" id="sb-{{ $name }}" @checked($row?->is_sandbox ?? true)>
                            <label class="form-check-label small fw-semibold" for="sb-{{ $name }}">Sandbox / test mode</label>
                        </div>
                    </div>

                    <div class="row g-2">
                        @foreach($gateway::fields() as $key => $field)
                            @php $secret = $field['secret'] ?? false; $has = filled($saved[$key] ?? null); @endphp
                            <div class="{{ ($field['type'] ?? 'text') === 'textarea' ? 'col-12' : 'col-md-6' }}">
                                <label class="small fw-semibold mb-1" for="{{ $name }}-{{ $key }}">{{ $field['label'] }} @if($field['required'] ?? true)<span class="text-danger">*</span>@endif</label>
                                @if(($field['type'] ?? 'text') === 'textarea')
                                    <textarea id="{{ $name }}-{{ $key }}" name="credentials[{{ $key }}]" rows="3" class="form-control form-control-sm font-monospace" spellcheck="false"
                                              placeholder="{{ $secret && $has ? '•••••••• saved — paste to replace' : '' }}">{{ $secret ? '' : ($saved[$key] ?? '') }}</textarea>
                                @else
                                    <input id="{{ $name }}-{{ $key }}" name="credentials[{{ $key }}]" type="{{ $secret ? 'password' : 'text' }}" class="form-control form-control-sm" autocomplete="new-password"
                                           value="{{ $secret ? '' : ($saved[$key] ?? '') }}" placeholder="{{ $secret && $has ? '•••••••• saved' : '' }}">
                                @endif
                                @if($field['help'] ?? null)<div class="form-text small">{{ $field['help'] }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                    </fieldset>

                    @foreach($urls[$name] ?? [] as $label => $url)
                        <div class="mt-3">
                            <label class="small fw-semibold mb-1">{{ $label }}</label>
                            <div class="input-group input-group-sm">
                                <input class="form-control font-monospace" value="{{ $url }}" readonly onclick="this.select()">
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(@js($url)); this.innerHTML='<i class=\'fas fa-check\'></i>'"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                    @endforeach
                    @if(in_array($name, ['bkash', 'nagad']))
                        <div class="small text-muted mt-3"><i class="fas fa-shield-alt me-1"></i>Payments are confirmed by a server-to-server call after the customer returns; no webhook URL is needed.</div>
                    @endif
                </div>
                @if($canEdit)
                    <div class="card-footer bg-transparent d-flex justify-content-between">
                        <button type="submit" form="test-{{ $name }}" class="btn btn-sm btn-outline-secondary" @disabled(! $row)><i class="fas fa-plug me-1"></i>Test connection</button>
                        <button class="btn btn-sm btn-savex"><i class="fas fa-save me-1"></i>Save</button>
                    </div>
                @endif
            </form>
            <form method="POST" action="{{ route('abs.gateways.test', $name) }}" id="test-{{ $name }}" class="d-none">@csrf</form>
        </div>
    @endforeach
</div>
@endsection
