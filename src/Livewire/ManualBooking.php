<?php

namespace ME\Abs\Livewire;

use Carbon\CarbonImmutable;
use Livewire\Component;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Livewire\Concerns\InteractsWithBusiness;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Booking\SlotFinder;
use ME\Abs\Services\Booking\SlotGenerator;
use ME\Models\User;

/**
 * Staff create a booking for a walk-in / phone customer. Notice and horizon rules do not apply,
 * availability and conflicts do.
 */
class ManualBooking extends Component
{
    use InteractsWithBusiness;

    public ?int $branchId = null;

    public ?int $serviceId = null;

    public string $staffChoice = 'any';

    public string $date = '';

    public ?string $selectedSlot = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public string $internalNotes = '';

    public bool $saving = false;

    public function mount(): void
    {
        $this->authorizeBusiness('abs_appointment.create');
        $this->date = CarbonImmutable::now($this->business()->timezone)->toDateString();

        // Prefill from the calendar ("click an empty slot"): ?date=YYYY-MM-DD&staff=ID
        $date = (string) request()->query('date', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $this->date = $date;
        }
        $staffId = (string) request()->query('staff', '');
        if (ctype_digit($staffId) && $this->business()->staff()->bookable()->whereKey((int) $staffId)->exists()) {
            $this->staffChoice = $staffId;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['branchId', 'serviceId', 'staffChoice', 'date'], true)) {
            $this->selectedSlot = null;
        }
        if ($property === 'branchId') {
            $this->staffChoice = 'any';
        }
        // Keep a staff member chosen on the calendar only if they offer the newly chosen service.
        if ($property === 'serviceId' && $this->staffChoice !== 'any') {
            $offers = app(SlotFinder::class)->offers($this->business(), $this->serviceId, null, $this->branchId ?: null);
            if (! $offers->contains(fn ($o) => (string) $o->staff->getKey() === $this->staffChoice)) {
                $this->staffChoice = 'any';
            }
        }
    }

    public function save(AppointmentBookingAction $action)
    {
        $this->authorizeBusiness('abs_appointment.create');

        $this->validate([
            'serviceId' => ['required', 'integer'],
            'selectedSlot' => ['required', 'string'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:filter', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'internalNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'serviceId.required' => 'Please choose a service.',
            'selectedSlot.required' => 'Please pick a time.',
        ]);

        $start = SlotFinder::parseSlot($this->selectedSlot);
        if (! $start) {
            $this->addError('selectedSlot', 'Pick a time.');

            return null;
        }

        try {
            $appointment = $action->execute(new BookingRequest(
                business: $this->business(),
                serviceId: (int) $this->serviceId,
                staffId: $this->staffChoice === 'any' ? null : (int) $this->staffChoice,
                branchId: $this->branchId ?: null,
                startsAt: $start,
                customerName: trim($this->name),
                customerEmail: trim($this->email),
                customerPhone: trim($this->phone) ?: null,
                customerNotes: trim($this->notes) ?: null,
                customer: User::query()->where('email', trim($this->email))->first(),
                source: 'admin',
                actor: auth()->user(),
                internalNotes: trim($this->internalNotes) ?: null,
            ));
        } catch (BookingException $e) {
            $this->addError('selectedSlot', $e->getMessage());
            $this->selectedSlot = null;

            return null;
        }

        session()->flash('success', 'Booking '.$appointment->booking_reference.' created.');

        return redirect()->route('abs.appointments.show', $appointment->getKey());
    }

    public function render()
    {
        $this->authorizeBusiness('abs_appointment.create');
        $business = $this->business();
        $finder = app(SlotFinder::class);
        $offers = $finder->offers($business, $this->serviceId, null, $this->branchId ?: null);
        $selected = $this->staffChoice === 'any' ? $offers : $offers->filter(fn ($o) => (string) $o->staff->getKey() === $this->staffChoice)->values();
        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1;
        $times = $validDate ? $finder->times($business, $selected, $this->date, $this->branchId ?: null, enforceWindow: false) : [];

        return view('abs::livewire.manual-booking', [
            'business' => $business,
            'branches' => $business->branches()->active()->orderBy('name')->get(['id', 'name']),
            'services' => $business->services()->active()->orderBy('name')->get(['id', 'name', 'duration_minutes', 'price_minor']),
            'staffOptions' => $offers->map(fn ($o) => ['id' => $o->staff->getKey(), 'name' => $o->staff->name]),
            'times' => $times,
            'hasOffers' => $selected->isNotEmpty(),
            'nextDate' => $validDate && $times === [] && $selected->isNotEmpty() ? $this->nextAvailableDate($business, $selected) : null,
        ]);
    }

    public function useDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $this->date = $date;
            $this->selectedSlot = null;
        }
    }

    /**
     * First date with a free time in the 60 days after the selected one, so staff are not left guessing.
     */
    private function nextAvailableDate($business, $offers): ?string
    {
        $from = CarbonImmutable::parse($this->date)->addDay()->toDateString();
        $dates = app(SlotGenerator::class)->availableDates($business, $offers, $from, 60, $this->branchId ?: null);

        return $dates[0] ?? null;
    }
}
