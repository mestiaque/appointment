<?php

namespace ME\Abs\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use ME\Abs\Actions\AppointmentReschedulingAction;
use ME\Abs\Enums\ActorType;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Appointment;
use ME\Abs\Services\Booking\BookingEligibilityService;
use ME\Abs\Services\Booking\ServiceOffer;
use ME\Abs\Services\Booking\SlotFinder;
use ME\Abs\Support\BusinessAccess;

/**
 * Reschedule for staff (admin appointment page) or the customer (signed manage page).
 * appointmentId and mode are locked: Livewire rejects a request that changes them.
 */
class RescheduleForm extends Component
{
    #[Locked]
    public int $appointmentId;

    #[Locked]
    public string $mode = 'customer';

    public string $date = '';

    public ?string $selectedSlot = null;

    public string $staffChoice = '';

    public string $reason = '';

    public function mount(Appointment $appointment, string $mode = 'customer'): void
    {
        $this->appointmentId = $appointment->getKey();
        $this->mode = $mode === 'staff' ? 'staff' : 'customer';
        $this->staffChoice = (string) $appointment->staff_id;
        $this->date = $appointment->localStartsAt()->toDateString();
        $this->appointment();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'staffChoice'], true)) {
            $this->selectedSlot = null;
        }
    }

    public function save(AppointmentReschedulingAction $action): void
    {
        $appointment = $this->appointment();
        $start = SlotFinder::parseSlot($this->selectedSlot);
        $this->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if (! $start) {
            $this->addError('selectedSlot', 'Pick a new time.');

            return;
        }

        try {
            $action->execute(
                $appointment,
                $start,
                $this->mode === 'staff' ? ActorType::Staff : ActorType::Customer,
                auth()->user(),
                $this->mode === 'staff' && ctype_digit($this->staffChoice) ? (int) $this->staffChoice : null,
                trim($this->reason) ?: null,
            );
        } catch (BookingException $e) {
            $this->addError('selectedSlot', $e->getMessage());
            $this->selectedSlot = null;

            return;
        }

        session()->flash('success', 'Appointment moved to '.$appointment->fresh()->localStartsAt()->format('D, d M Y h:i A').'.');
        $this->redirect($this->mode === 'staff' ? route('abs.appointments.show', $appointment->getKey()) : $appointment->manageUrl());
    }

    public function render()
    {
        $appointment = $this->appointment();
        $business = $appointment->business;
        $finder = app(SlotFinder::class);
        $staffId = $this->mode === 'staff' && ctype_digit($this->staffChoice) ? (int) $this->staffChoice : $appointment->staff_id;
        $offers = $finder->offers($business, $appointment->service_id, $staffId, $appointment->branch_id);
        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1;

        $times = $validDate
            ? $finder->times($business, $this->withSnapshotDuration($offers, $appointment), $this->date, $appointment->branch_id, $this->mode === 'customer', $appointment->getKey())
            : [];

        return view('abs::livewire.reschedule-form', [
            'appointment' => $appointment,
            'times' => $times,
            'staffOptions' => $this->mode === 'staff'
                ? app(BookingEligibilityService::class)->eligibleOffers($business, $appointment->service, $appointment->branch)->map(fn ($o) => ['id' => $o->staff->getKey(), 'name' => $o->staff->name])
                : collect(),
        ]);
    }

    /**
     * Use the booking's own duration (item snapshot), not the service's current one.
     */
    private function withSnapshotDuration($offers, Appointment $appointment)
    {
        $minutes = (int) $appointment->items()->sum('duration_minutes');

        return $offers->map(fn ($o) => new ServiceOffer($o->service, $o->staff, $minutes ?: $o->durationMinutes, $o->unitPriceMinor));
    }

    private function appointment(): Appointment
    {
        $appointment = Appointment::query()->with('business', 'service', 'branch')->findOrFail($this->appointmentId);

        if ($this->mode === 'staff') {
            $access = app(BusinessAccess::class);
            $user = auth()->user();
            abort_unless($access->can($user, $appointment->business, 'abs_appointment.reschedule'), 403);
            $restricted = $access->restrictedStaffId($user, $appointment->business);
            abort_if($restricted !== null && $restricted !== $appointment->staff_id, 403);
        }

        return $appointment;
    }
}
