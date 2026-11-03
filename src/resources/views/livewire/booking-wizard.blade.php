@php
    $labels = [1 => 'Location', 2 => 'Service', 3 => 'Staff', 4 => 'Time', 5 => 'Details', 6 => 'Confirm'];
    if ($this->branches->isEmpty()) { unset($labels[1]); }
    $tz = $this->business->timezone;
    $money = fn (int $minor) => \ME\Abs\Support\Currency::format($minor, $this->business->currency);
    // Category icon and colour by keyword, so any industry gets a sensible look without configuration.
    $look = function (?string $name): array {
        $n = mb_strtolower((string) $name);
        foreach ([
            ['medic|general|physician', 'fa-stethoscope', '#0ea5e9'], ['child|pediatric|paediatric|baby', 'fa-baby', '#f59e0b'],
            ['women|gyn|obst', 'fa-venus', '#ec4899'], ['cardio|heart', 'fa-heartbeat', '#ef4444'], ['dental|teeth|tooth', 'fa-tooth', '#14b8a6'],
            ['diagnos|lab|test|ecg|x-ray', 'fa-wave-square', '#8b5cf6'], ['hair|cut|barber|styl', 'fa-cut', '#d946ef'],
            ['spa|beauty|skin|massage|care', 'fa-spa', '#10b981'], ['repair|service|maint', 'fa-tools', '#64748b'],
            ['photo|studio', 'fa-camera', '#6366f1'], ['train|class|course|lesson', 'fa-graduation-cap', '#0284c7'], ['consult', 'fa-comments', '#0ea5e9'],
        ] as [$pattern, $icon, $color]) {
            if (preg_match('/'.$pattern.'/', $n)) { return [$icon, $color]; }
        }
        return ['fa-concierge-bell', '#6366f1'];
    };
    $initials = fn (string $name) => collect(preg_split('/\s+/', trim(preg_replace('/^(dr|mr|mrs|ms)\.?\s+/i', '', $name))))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $sum = $this->summary;
@endphp

<div class="row g-3">
    <div class="col-lg-8">
        <div class="abs-card p-3 p-md-4">
            {{-- Progress --}}
            <ol class="abs-progress list-unstyled d-flex mb-4">
                @foreach($labels as $n => $label)
                    @php $state = $n < $step ? 'done' : ($n === $step ? 'current' : ''); @endphp
                    <li class="{{ $state }}">
                        <button type="button" @if($state === 'done' && $n <= 5) wire:click="goToStep({{ $n }})" @else disabled @endif aria-current="{{ $state === 'current' ? 'step' : 'false' }}">
                            <span class="num">@if($state === 'done')<i class="fas fa-check"></i>@else{{ $loop->iteration }}@endif</span>
                            <span class="lbl d-none d-sm-block">{{ $label }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>

            @error('booking')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            {{-- 1. Location --}}
            @if($step === 1)
                <h2 class="abs-h">Where would you like to go?</h2>
                <div class="row g-2">
                    @foreach($this->branches as $branch)
                        <div class="col-sm-6" wire:key="b-{{ $branch->id }}">
                            <button type="button" wire:click="chooseBranch({{ $branch->id }})" class="abs-choice abs-tile {{ $branchId === $branch->id ? 'active' : '' }}">
                                <span class="ico" style="--c:#0ea5e9"><i class="fas fa-map-marker-alt"></i></span>
                                <span class="body"><span class="t">{{ $branch->name }}</span><span class="s">{{ $branch->address }}</span></span>
                            </button>
                        </div>
                    @endforeach
                    <div class="col-sm-6">
                        <button type="button" wire:click="chooseBranch(null)" class="abs-choice abs-tile">
                            <span class="ico" style="--c:#8b5cf6"><i class="fas fa-globe-asia"></i></span>
                            <span class="body"><span class="t">Any location</span><span class="s">Show every available time</span></span>
                        </button>
                    </div>
                </div>
            @endif

            {{-- 2. Service --}}
            @if($step === 2)
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="abs-h mb-0">Choose a service</h2>
                    <div class="abs-search">
                        <i class="fas fa-search"></i>
                        <input type="search" wire:model.live.debounce.250ms="search" class="form-control form-control-sm" placeholder="Search services" aria-label="Search services">
                    </div>
                </div>
                @if(count($this->categories) > 1)
                    <div class="abs-pills d-flex gap-2 overflow-auto pb-2 mb-2">
                        <button type="button" wire:click="chooseCategory('')" class="abs-pill {{ $category === '' ? 'active' : '' }}">All <span>{{ $this->services->count() }}</span></button>
                        @foreach($this->categories as $cat)
                            @php [$icon] = $look($cat['name']); @endphp
                            <button type="button" wire:key="cat-{{ $cat['id'] }}" wire:click="chooseCategory('{{ $cat['id'] }}')" class="abs-pill {{ $category === (string) $cat['id'] ? 'active' : '' }}">
                                <i class="fas {{ $icon }}"></i>{{ $cat['name'] }} <span>{{ $cat['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <div class="row g-2" wire:loading.class="opacity-75" wire:target="search,chooseCategory">
                    @forelse($this->visibleServices as $service)
                        @php [$icon, $color] = $look($service->category?->name ?? $service->name); @endphp
                        <div class="col-sm-6 col-xl-4" wire:key="s-{{ $service->id }}">
                            <button type="button" wire:click="chooseService({{ $service->id }})" class="abs-choice abs-svc {{ $serviceId === $service->id ? 'active' : '' }}">
                                <span class="ico" style="--c:{{ $color }}"><i class="fas {{ $icon }}"></i></span>
                                <span class="t">{{ $service->name }}</span>
                                @if($service->description)<span class="d" title="{{ $service->description }}">{{ $service->description }}</span>@endif
                                <span class="meta"><span><i class="far fa-clock me-1"></i>{{ $service->duration_minutes }} min</span><b>{{ $money($service->price_minor) }}</b></span>
                            </button>
                        </div>
                    @empty
                        <div class="col-12"><div class="text-muted small py-4 text-center">{{ $search ? 'No service matches "'.$search.'".' : 'This business has no bookable services yet.' }}</div></div>
                    @endforelse
                </div>
            @endif

            {{-- 3. Staff --}}
            @if($step === 3)
                <h2 class="abs-h">Who would you like to see?</h2>
                <div class="row g-2">
                    <div class="col-sm-6 col-xl-4">
                        <button type="button" wire:click="chooseStaff('any')" class="abs-choice abs-tile {{ $staffChoice === 'any' ? 'active' : '' }}">
                            <span class="avatar" style="--c:#6366f1"><i class="fas fa-users"></i></span>
                            <span class="body"><span class="t">Any available</span><span class="s">Earliest free times</span></span>
                        </button>
                    </div>
                    @foreach($staffOptions as $i => $option)
                        @php $c = ['#0ea5e9', '#ec4899', '#10b981', '#f59e0b', '#8b5cf6', '#14b8a6'][$i % 6]; @endphp
                        <div class="col-sm-6 col-xl-4" wire:key="st-{{ $option['id'] }}">
                            <button type="button" wire:click="chooseStaff('{{ $option['id'] }}')" class="abs-choice abs-tile {{ $staffChoice === (string) $option['id'] ? 'active' : '' }}">
                                <span class="avatar" style="--c:{{ $c }}">{{ $initials($option['name']) }}</span>
                                <span class="body"><span class="t">{{ $option['name'] }}</span>@if($option['title'])<span class="s">{{ $option['title'] }}</span>@endif</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- 4. Date & time --}}
            @if($step === 4)
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="abs-h mb-0">Pick a date and time</h2>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-glass btn-sm" wire:click="shiftWeek(-1)" aria-label="Previous week"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" class="btn btn-glass btn-sm" wire:click="shiftWeek(1)" aria-label="Next week"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="abs-days d-flex gap-2 overflow-auto pb-2 mb-3" wire:loading.class="opacity-50" wire:target="shiftWeek,chooseDate">
                    @foreach($this->dates as $d)
                        <button type="button" wire:key="d-{{ $d['date'] }}" wire:click="chooseDate('{{ $d['date'] }}')" @disabled(! $d['available'])
                                class="abs-day-chip {{ $date === $d['date'] ? 'active' : '' }}">
                            <span class="wd">{{ \Illuminate\Support\Str::before($d['label'], ',') }}</span>
                            <span class="dn">{{ \Illuminate\Support\Str::of($d['label'])->after(', ')->before(' ') }}</span>
                            <span class="mo">{{ \Illuminate\Support\Str::afterLast($d['label'], ' ') }}</span>
                        </button>
                    @endforeach
                </div>
                @if($date)
                    @php $groups = collect($this->times)->groupBy(fn ($t) => (int) \Carbon\CarbonImmutable::parse($t['start'])->setTimezone($tz)->format('G') < 12 ? 'Morning' : ((int) \Carbon\CarbonImmutable::parse($t['start'])->setTimezone($tz)->format('G') < 17 ? 'Afternoon' : 'Evening')); @endphp
                    <div wire:loading.class="opacity-50" wire:target="chooseDate">
                        @forelse($groups as $part => $times)
                            <div class="small fw-semibold text-muted mb-1 mt-2"><i class="fas {{ $part === 'Morning' ? 'fa-sun' : ($part === 'Afternoon' ? 'fa-cloud-sun' : 'fa-moon') }} me-1"></i>{{ $part }}</div>
                            <div class="abs-times mb-2">
                                @foreach($times as $t)
                                    <button type="button" wire:key="t-{{ $t['start'] }}" wire:click="chooseSlot('{{ $t['start'] }}')" class="abs-time {{ $selectedSlot === $t['start'] ? 'active' : '' }}">{{ $t['label'] }}</button>
                                @endforeach
                            </div>
                        @empty
                            <p class="text-muted small">No times left on this day — try another date.</p>
                        @endforelse
                    </div>
                    <p class="small text-muted mt-2 mb-0"><i class="far fa-clock me-1"></i>Times are in {{ $tz }}.</p>
                @elseif(! collect($this->dates)->contains('available', true))
                    <p class="text-muted small">No availability this week. Try the next week <i class="fas fa-arrow-right"></i></p>
                @else
                    <p class="text-muted small">Select a date to see free times.</p>
                @endif
            @endif

            {{-- 5. Details --}}
            @if($step === 5)
                <h2 class="abs-h">Your details</h2>
                @guest
                    <div class="small mb-3 text-muted">Have an account? <a href="{{ route('abs.account.login') }}">Sign in</a> to fill this in and keep your bookings together.</div>
                @endguest
                <form wire:submit="toSummary" novalidate>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="abs-name">Full name *</label>
                            <input id="abs-name" wire:model="name" autocomplete="name" class="form-control @error('name') is-invalid @enderror">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="abs-phone">Phone {{ $settings->require_customer_phone ? '*' : '' }}</label>
                            <input id="abs-phone" type="tel" wire:model="phone" autocomplete="tel" class="form-control @error('phone') is-invalid @enderror">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="abs-email">E-mail *</label>
                            <input id="abs-email" type="email" wire:model="email" autocomplete="email" class="form-control @error('email') is-invalid @enderror">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="abs-notes">Note for the business <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea id="abs-notes" wire:model="notes" rows="2" maxlength="1000" class="form-control @error('notes') is-invalid @enderror"></textarea>
                            <div class="form-text small">Please don't include sensitive personal information.</div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="abs-back" wire:click="back"><span class="arr"><i class="fas fa-arrow-left"></i></span>Back</button>
                        <button type="submit" class="btn btn-primary px-4">Continue</button>
                    </div>
                </form>
            @endif

            {{-- 6. Confirm --}}
            @if($step === 6 && $this->quote)
                @php $q = $this->quote; @endphp
                <h2 class="abs-h">Review and confirm</h2>
                <div class="abs-review mb-3">
                    <div><span>Name</span><b>{{ $name }}</b></div>
                    <div><span>Contact</span><b>{{ $email }}{{ $phone ? ' · '.$phone : '' }}</b></div>
                    @if($notes)<div><span>Note</span><b>{{ $notes }}</b></div>@endif
                    <div><span>Price</span><b>{{ $q['subtotal'] }}</b></div>
                    @if($q['tax'])<div><span>Tax</span><b>{{ $q['tax'] }}</b></div>@endif
                    <div class="total"><span>Total</span><b>{{ $q['total'] }}</b></div>
                    @if($q['varies'])<div class="small text-muted">The final price can differ slightly depending on the staff member.</div>@endif
                </div>

                <div class="small fw-semibold mb-2">Payment</div>
                @if(in_array($settings->payment_mode->value, ['online_required', 'deposit_required']) && $onlinePossible)
                    <div class="abs-pay active mb-3">
                        <span class="ico" style="--c:#6366f1"><i class="fas fa-credit-card"></i></span>
                        <div class="small"><b>{{ $settings->payment_mode->value === 'deposit_required' ? 'Deposit online now'.($q['deposit'] ? ' — '.$q['deposit'] : '') : 'Pay online now — '.$q['total'] }}</b>
                            <div class="text-muted">Required to secure this booking.{{ $settings->payment_mode->value === 'deposit_required' ? ' The rest is paid at the visit.' : '' }}</div></div>
                    </div>
                @else
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="abs-pay h-100 {{ ! $payOnline ? 'active' : '' }}">
                                <input type="radio" class="form-check-input" wire:model.live="payOnline" value="0" name="abs-pay">
                                <span class="ico" style="--c:#10b981"><i class="fas fa-store"></i></span>
                                <span class="small"><b>Pay at the counter</b><span class="d-block text-muted">Cash or card on your visit</span></span>
                            </label>
                        </div>
                        <div class="col-sm-6">
                            <label class="abs-pay h-100 {{ $payOnline ? 'active' : '' }} {{ $onlinePossible ? '' : 'disabled' }}" @unless($onlinePossible) title="Online payment is not set up for this business yet" @endunless>
                                <input type="radio" class="form-check-input" wire:model.live="payOnline" value="1" name="abs-pay" @disabled(! $onlinePossible)>
                                <span class="ico" style="--c:#6366f1"><i class="fas fa-credit-card"></i></span>
                                <span class="small"><b>Pay online now</b><span class="d-block text-muted">{{ $onlinePossible ? 'Card / wallet, secure checkout' : 'Not available yet' }}</span></span>
                            </label>
                        </div>
                    </div>
                @endif
                @if($onlinePossible && ($payOnline || in_array($settings->payment_mode->value, ['online_required', 'deposit_required'])) && count($gatewayOptions))
                    <div class="small fw-semibold mb-2">Pay with</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach($gatewayOptions as $key => $label)
                            <label class="abs-gw {{ ($gateway ?: array_key_first($gatewayOptions)) === $key ? 'active' : '' }}" style="--gw: {{ ['bkash' => '#e2136e', 'nagad' => '#f6921e', 'sslcommerz' => '#1e3a8a', 'stripe' => '#635bff', 'paypal' => '#003087'][$key] ?? '#6366f1' }}">
                                <input type="radio" class="d-none" wire:model.live="gateway" value="{{ $key }}">
                                <span class="dot"></span>{{ \Illuminate\Support\Str::before($label, ' (') }}
                            </label>
                        @endforeach
                    </div>
                @endif
                @if($settings->approval_mode->value === 'approval')
                    <p class="small text-muted"><i class="fas fa-info-circle me-1"></i>This business confirms bookings manually — you'll get an e-mail once approved.</p>
                @endif

                <div class="d-flex justify-content-between mt-3">
                    <button type="button" class="abs-back" wire:click="back"><span class="arr"><i class="fas fa-arrow-left"></i></span>Back</button>
                    <button type="button" class="btn btn-primary btn-lg px-4" wire:click="book" wire:loading.attr="disabled" wire:target="book">
                        <span wire:loading.remove wire:target="book"><i class="fas fa-check me-1"></i>Confirm booking</span>
                        <span wire:loading wire:target="book"><span class="spinner-border spinner-border-sm me-1"></span> Booking…</span>
                    </button>
                </div>
            @endif

            @if(in_array($step, [2, 3, 4]) && $step > ($this->branches->isEmpty() ? 2 : 1))
                <div class="mt-3"><button type="button" class="abs-back" wire:click="back"><span class="arr"><i class="fas fa-arrow-left"></i></span>Back</button></div>
            @endif
        </div>
    </div>

    {{-- Summary --}}
    <div class="col-lg-4">
        <div class="abs-card p-3 p-md-4 abs-summary">
            <h3 class="h6 fw-bold mb-3"><i class="fas fa-receipt me-1 text-primary"></i> Your booking</h3>
            @foreach([['branch', 'fa-map-marker-alt', 'Location'], ['service', 'fa-concierge-bell', 'Service'], ['staff', 'fa-user-md', 'With'], ['when', 'fa-calendar-alt', 'When']] as [$key, $icon, $label])
                @continue($key === 'branch' && $this->branches->isEmpty())
                <div class="row-item {{ $sum[$key] ? '' : 'empty' }}">
                    <i class="fas {{ $icon }}"></i>
                    <div><div class="lbl">{{ $label }}</div><div class="val">{{ $sum[$key] ?? 'Not chosen yet' }}@if($key === 'service' && $sum['duration']) <span class="text-muted fw-normal">· {{ $sum['duration'] }}</span>@endif</div></div>
                </div>
            @endforeach
            <div class="d-flex justify-content-between align-items-center pt-3 mt-2 border-top border-white">
                <span class="small text-muted">Total</span>
                <span class="h5 fw-bold mb-0">{{ $sum['price'] ?? '—' }}</span>
            </div>
            @if($settings->allow_customer_cancellation)
                <div class="small text-muted mt-3"><i class="fas fa-shield-alt me-1 text-success"></i>Cancel or change online up to {{ $settings->cancellation_notice_minutes >= 60 ? intdiv($settings->cancellation_notice_minutes, 60).' h' : $settings->cancellation_notice_minutes.' min' }} before.</div>
            @endif
        </div>
    </div>
</div>
