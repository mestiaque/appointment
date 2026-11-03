<?php

namespace ME\Abs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Exceptions\InvalidTransitionException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\AppointmentStatusHistory;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Booking\AvailabilityService;
use ME\Abs\Services\Notifications\AppointmentNotifier;
use ME\Models\User;

/**
 * The only code that changes Appointment::status. Every change is checked against
 * AppointmentStatus::allowedTransitions() and recorded with actor, time and reason.
 */
class AppointmentStateMachine
{
    public function __construct(private AppointmentNotifier $notifier, private AvailabilityService $availability) {}

    public function transition(
        Appointment $appointment,
        AppointmentStatus $to,
        ActorType $actorType,
        ?User $actor = null,
        ?string $reason = null,
    ): Appointment {
        return DB::transaction(function () use ($appointment, $to, $actorType, $actor, $reason) {
            // Same lock order as booking (staff row, then appointments) so the two can never deadlock each other.
            $staffId = Appointment::query()->whereKey($appointment->getKey())->value('staff_id');
            Staff::query()->whereKey($staffId)->lockForUpdate()->first();

            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidTransitionException::between($from, $to);
            }

            if ($timeRule = $this->tooEarly($locked, $to)) {
                throw new BookingException($timeRule);
            }

            if ($this->mustRecheckSlot($locked, $from, $to) && ! $this->slotStillFree($locked)) {
                throw BookingException::slotUnavailable();
            }

            $locked->status = $to;
            $now = CarbonImmutable::now();

            if ($to === AppointmentStatus::Confirmed) {
                $locked->confirmed_at = $now;
            }
            if (in_array($to, [AppointmentStatus::Cancelled, AppointmentStatus::Rejected], true)) {
                $locked->cancelled_at = $now;
                $locked->cancellation_reason = $reason ? mb_substr($reason, 0, 255) : null;
            }
            if ($from === AppointmentStatus::AwaitingPayment) {
                $locked->hold_expires_at = null;
            }

            $locked->save();
            $this->record($locked, $from, $to, $actorType, $actor, $reason);

            $this->notifier->statusChangedAfterCommit($locked, $from, $to);

            $appointment->setRawAttributes($locked->getAttributes(), true);

            return $appointment;
        }, 3);
    }

    /**
     * Visit statuses follow the clock: check-in / start only on the appointment's local day,
     * completed / no-show only after the start time. Returns the reason, or null when allowed.
     */
    public function tooEarly(Appointment $appointment, AppointmentStatus $to): ?string
    {
        $now = CarbonImmutable::now();

        return match ($to) {
            AppointmentStatus::CheckedIn, AppointmentStatus::InProgress => $now->lessThan($appointment->localStartsAt()->startOfDay())
                ? 'Check-in and start are possible from the day of the appointment.' : null,
            AppointmentStatus::Completed, AppointmentStatus::NoShow => $now->lessThan($appointment->starts_at)
                ? 'This can be set only after the appointment start time.' : null,
            default => null,
        };
    }

    /**
     * A pending request (when pending requests do not hold their slot) or an expired payment hold did not
     * reserve its time, so someone else may have booked it meanwhile.
     */
    private function mustRecheckSlot(Appointment $appointment, AppointmentStatus $from, AppointmentStatus $to): bool
    {
        $pendingBlocks = $appointment->business->settings()->pending_blocks_slot;
        $targetBlocks = $to === AppointmentStatus::Confirmed || ($to === AppointmentStatus::Pending && $pendingBlocks);

        return $targetBlocks && match ($from) {
            AppointmentStatus::Pending => ! $pendingBlocks,
            AppointmentStatus::AwaitingPayment => $appointment->hold_expires_at === null || $appointment->hold_expires_at->isPast(),
            default => false,
        };
    }

    private function slotStillFree(Appointment $appointment): bool
    {
        return $this->availability->blockingAppointments(
            $appointment->business,
            $appointment->staff_id,
            $appointment->blocked_starts_at->utc(),
            $appointment->blocked_ends_at->utc(),
            $appointment->getKey(),
            lock: true,
        )->isEmpty();
    }

    public function record(
        Appointment $appointment,
        ?AppointmentStatus $from,
        AppointmentStatus $to,
        ActorType $actorType,
        ?User $actor = null,
        ?string $reason = null,
    ): void {
        AppointmentStatusHistory::query()->create([
            'appointment_id' => $appointment->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actorType->value,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
        ]);
    }
}
