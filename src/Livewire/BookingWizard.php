<?php

namespace ME\Abs\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Business;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Booking\PriceCalculator;
use ME\Abs\Services\Booking\SlotFinder;
use ME\Abs\Services\Payments\CheckoutService;
use ME\Abs\Support\Currency;

/**
 * Public booking wizard: branch -> service -> staff -> date/time -> details -> confirm.
 * Holds only ids and the customer's own input; prices and availability are recomputed on the server.
 */
class BookingWizard extends Component
{
    #[Locked]
    public int $businessId;

    #[Locked]
    public ?string $bookedReference = null;

    public int $step = 1;

    public ?int $branchId = null;

    public ?int $serviceId = null;

    public string $staffChoice = 'any';

    public string $weekStart = '';

    public ?string $date = null;

    public ?string $selectedSlot = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public bool $payOnline = false;

    /** Service list filters (presentation only; chooseService() still checks the full list). */
    public string $category = '';

    /** Gateway chosen for online payment (bkash, nagad, sslcommerz, …). */
    public string $gateway = '';

    public string $search = '';

    public function mount(Business $business): void
    {
        abort_unless($business->isActive(), 404);
        $this->businessId = $business->getKey();
        $this->weekStart = CarbonImmutable::now($business->timezone)->toDateString();

        if ($user = auth()->user()) {
            $this->name = (string) $user->name;
            $this->email = (string) $user->email;
            $this->phone = (string) ($user->phone ?? '');
        }

        if ($this->branches->isEmpty()) {
            $this->step = 2;
        }
    }

    #[Computed]
    public function business(): Business
    {
        $business = Business::query()->active()->findOrFail($this->businessId);

        return $business;
    }

    #[Computed]
    public function branches()
    {
        return $this->business->branches()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'address']);
    }

    #[Computed]
    public function services()
    {
        return $this->business->services()->active()->whereHas('staff', fn ($q) => $q->bookable())
            ->with('category:id,name')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'duration_minutes', 'price_minor']);
    }

    /**
     * @return array<int, array{id: int, name: string, count: int}>
     */
    #[Computed]
    public function categories(): array
    {
        return $this->services->filter(fn ($s) => $s->category)->groupBy('category_id')
            ->map(fn ($group) => ['id' => $group->first()->category->id, 'name' => $group->first()->category->name, 'count' => $group->count()])
            ->sortBy('name')->values()->all();
    }

    #[Computed]
    public function visibleServices()
    {
        $term = mb_strtolower(trim($this->search));

        return $this->services
            ->when($this->category !== '', fn ($c) => $c->where('category_id', (int) $this->category))
            ->when($term !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s->name.' '.$s->description.' '.$s->category?->name), $term)))
            ->values();
    }

    /**
     * What the customer picked so far, for the summary card.
     *
     * @return array<string, ?string>
     */
    #[Computed]
    public function summary(): array
    {
        $service = $this->serviceId ? $this->services->firstWhere('id', $this->serviceId) : null;
        $staff = $this->staffChoice !== 'any' ? $this->offers->first(fn ($o) => (string) $o->staff->getKey() === $this->staffChoice)?->staff : null;
        $slot = $this->selectedSlot ? CarbonImmutable::parse($this->selectedSlot)->setTimezone($this->business->timezone) : null;

        return [
            'branch' => $this->branchId ? $this->branches->firstWhere('id', $this->branchId)?->name : ($this->step > 1 && $this->branches->isNotEmpty() ? 'Any location' : null),
            'service' => $service?->name,
            'duration' => $service ? ($this->quote['duration'] ?? $service->duration_minutes).' min' : null,
            'staff' => $staff?->name ?? ($this->step > 3 ? 'Any available' : null),
            'when' => $slot ? $slot->format('D, d M · h:i A') : ($this->date ? CarbonImmutable::parse($this->date)->format('D, d M') : null),
            'price' => $this->quote['total'] ?? ($service ? Currency::format($service->price_minor, $this->business->currency) : null),
        ];
    }

    public function chooseCategory(string $category): void
    {
        $this->category = collect($this->categories)->contains('id', (int) $category) ? $category : '';
    }

    public function goToStep(int $step): void
    {
        // Only backwards: later steps need the earlier choices.
        if ($step >= ($this->branches->isEmpty() ? 2 : 1) && $step < $this->step && $step <= 5) {
            $this->step = $step;
        }
    }

    #[Computed]
    public function offers()
    {
        return app(SlotFinder::class)->offers($this->business, $this->serviceId, null, $this->branchId);
    }

    #[Computed]
    public function dates(): array
    {
        return app(SlotFinder::class)->dates($this->business, $this->selectedOffers(), $this->weekStart, 7, $this->branchId);
    }

    #[Computed]
    public function times(): array
    {
        return $this->date ? app(SlotFinder::class)->times($this->business, $this->selectedOffers(), $this->date, $this->branchId) : [];
    }

    #[Computed]
    public function quote(): ?array
    {
        $offer = $this->selectedOffers()->first();
        if (! $offer) {
            return null;
        }

        $quote = app(PriceCalculator::class)->quote($offer, $this->business->settings());

        return [
            'service' => $offer->service->name,
            'duration' => $offer->durationMinutes,
            'subtotal' => Currency::format($quote->subtotalMinor, $this->business->currency),
            'tax' => $quote->taxMinor ? Currency::format($quote->taxMinor, $this->business->currency) : null,
            'total' => Currency::format($quote->totalMinor, $this->business->currency),
            'deposit' => $quote->depositMinor ? Currency::format($quote->depositMinor, $this->business->currency) : null,
            // With "any staff", the final price can differ if staff have price overrides.
            'varies' => $this->staffChoice === 'any' && $this->offers->pluck('unitPriceMinor')->unique()->count() > 1,
        ];
    }

    public function chooseBranch(?int $branchId): void
    {
        $this->branchId = $branchId && $this->branches->contains('id', $branchId) ? $branchId : null;
        $this->resetSelection('service');
        $this->step = 2;
    }

    public function chooseService(int $serviceId): void
    {
        if (! $this->services->contains('id', $serviceId)) {
            return;
        }

        $this->serviceId = $serviceId;
        $this->resetSelection('staff');
        $this->step = 3;
    }

    public function chooseStaff(string $choice): void
    {
        $valid = $choice === 'any' || $this->offers->contains(fn ($o) => (string) $o->staff->getKey() === $choice);
        if (! $valid) {
            return;
        }

        $this->staffChoice = $choice;
        $this->resetSelection('date');
        $this->step = 4;
    }

    public function shiftWeek(int $direction): void
    {
        $tz = $this->business->timezone;
        $start = CarbonImmutable::parse($this->weekStart)->addDays($direction > 0 ? 7 : -7);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $limit = $today->addDays($this->business->settings()->max_days_ahead);

        if ($start->lessThan($today)) {
            $start = $today;
        }
        if ($start->greaterThan($limit)) {
            return;
        }

        $this->weekStart = $start->toDateString();
        $this->date = null;
        $this->selectedSlot = null;
    }

    public function chooseDate(string $date): void
    {
        if (! collect($this->dates)->contains(fn ($d) => $d['date'] === $date && $d['available'])) {
            return;
        }

        $this->date = $date;
        $this->selectedSlot = null;
    }

    public function chooseSlot(string $start): void
    {
        if (! collect($this->times)->contains('start', $start)) {
            return;
        }

        $this->selectedSlot = $start;
        $this->step = 5;
    }

    public function toSummary(): void
    {
        $settings = $this->business->settings();
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:filter', 'max:150'],
            'phone' => [$settings->require_customer_phone ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]{6,40}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->step = 6;
    }

    public function back(): void
    {
        $this->step = max($this->branches->isEmpty() ? 2 : 1, $this->step - 1);
    }

    public function book(AppointmentBookingAction $action, CheckoutService $checkout)
    {
        if ($this->bookedReference) {
            return null; // already booked; ignore a double submit
        }

        $key = 'abs-book:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, (int) config('abs.rate_limits.booking', 10))) {
            $this->addError('booking', 'Too many booking attempts. Please wait a minute and try again.');

            return null;
        }
        RateLimiter::hit($key, 60);

        $this->toSummary();
        $start = SlotFinder::parseSlot($this->selectedSlot);
        if (! $start || ! $this->serviceId) {
            $this->step = 4;

            return null;
        }

        try {
            $appointment = $action->execute(new BookingRequest(
                business: $this->business,
                serviceId: $this->serviceId,
                staffId: $this->staffChoice === 'any' ? null : (int) $this->staffChoice,
                branchId: $this->branchId,
                startsAt: $start,
                customerName: trim($this->name),
                customerEmail: trim($this->email),
                customerPhone: trim($this->phone) ?: null,
                customerNotes: trim($this->notes) ?: null,
                customer: auth()->user(),
                payOnline: $this->payOnline,
            ));
        } catch (BookingException $e) {
            $this->addError('booking', $e->getMessage());
            $this->selectedSlot = null;
            $this->step = 4;
            unset($this->times, $this->dates);

            return null;
        }

        $this->bookedReference = $appointment->booking_reference;

        if ($appointment->status->value === 'awaiting_payment') {
            try {
                return redirect()->away($checkout->start($appointment, $this->gateway ?: null)->checkout_url);
            } catch (PaymentException $e) {
                session()->flash('error', $e->getMessage());
            }
        }

        return redirect()->to($appointment->manageUrl());
    }

    public function render()
    {
        $settings = $this->business->settings();

        return view('abs::livewire.booking-wizard', [
            'settings' => $settings,
            'onlinePossible' => $settings->payment_mode->needsOnlineGateway() && ($gateways = app(GatewayManager::class)->forBusiness($this->business))->isNotEmpty(),
            'gatewayOptions' => ($gateways ?? collect())->map(fn ($g) => $g->label())->all(),
            'staffOptions' => $this->offers->map(fn ($o) => ['id' => $o->staff->getKey(), 'name' => $o->staff->name, 'title' => $o->staff->title])->all(),
        ]);
    }

    private function selectedOffers()
    {
        return $this->staffChoice === 'any'
            ? $this->offers
            : $this->offers->filter(fn ($o) => (string) $o->staff->getKey() === $this->staffChoice)->values();
    }

    private function resetSelection(string $from): void
    {
        $order = ['service', 'staff', 'date'];
        $index = array_search($from, $order, true);
        if ($index <= 0) {
            $this->serviceId = null;
        }
        if ($index <= 1) {
            $this->staffChoice = 'any';
        }
        $this->date = null;
        $this->selectedSlot = null;
        unset($this->offers, $this->dates, $this->times, $this->quote);
    }
}
