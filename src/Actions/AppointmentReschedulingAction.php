<?php

namespace ME\Abs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\AppointmentRescheduleHistory;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Booking\AvailabilityService;
use ME\Abs\Services\Booking\BookingEligibilityService;
use ME\Abs\Services\Booking\BookingWindow;
use ME\Abs\Services\Notifications\AppointmentNotifier;
use ME\Abs\Support\Interval;
use ME\Models\User;

/**
 * Moves an appointment to a new start (and optionally another staff member).
 *
 * The old and new times are the same row: the row is updated only after the new interval passed the
 * availability check under lock, so the old reservation is never released before the new one is secured.
 * Duration and buffers come from the booking's item snapshot, not from the current service settings.
 */
class AppointmentReschedulingAction
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingEligibilityService $eligibility,
        private AppointmentNotifier $notifier,
    ) {}

    public function execute(
        Appointment $appointment,
        CarbonImmutable $newStart,
        ActorType $actorType,
        ?User $actor = null,
        ?int $newStaffId = null,
        ?string $reason = null,
    ): Appointment {
        $business = $appointment->business;
        $settings = $business->settings();
        $newStart = $newStart->utc()->startOfMinute();

        if (! in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
            throw new BookingException('Only pending or confirmed appointments can be rescheduled.');
        }

        if ($actorType === ActorType::Customer) {
            $this->assertCustomerMayReschedule($appointment, $newStart);
        }

        $staffId = $newStaffId ?? $appointment->staff_id;
        if ($staffId !== $appointment->staff_id) {
            $this->eligibility->offer($business, $this->eligibility->resolveService($business, (int) $appointment->service_id), $staffId, $appointment->branch);
        }

        $item = $appointment->items()->firstOrFail();
        $duration = (int) $appointment->items()->sum('duration_minutes');

        $updated = DB::transaction(function () use ($appointment, $business, $newStart, $staffId, $item, $duration, $actorType, $actor, $reason) {
            $staffIds = array_unique([$appointment->staff_id, $staffId]);
            sort($staffIds);
            Staff::query()->whereIn('id', $staffIds)->orderBy('id')->lockForUpdate()->get();

            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
                throw new BookingException('This appointment can no longer be rescheduled.');
            }

            $staff = Staff::query()->findOrFail($staffId);
            $service = new Interval($newStart, $newStart->addMinutes($duration));
            $blocked = new Interval(
                $newStart->subMinutes($item->buffer_before_minutes),
                $service->end->addMinutes($item->buffer_after_minutes),
            );

            if (! $this->availability->isAvailable($business, $staff, $service, $blocked, $locked->branch_id, $locked->getKey(), true)) {
                throw BookingException::slotUnavailable();
            }

            $history = AppointmentRescheduleHistory::query()->create([
                'appointment_id' => $locked->getKey(),
                'old_starts_at' => $locked->starts_at,
                'old_ends_at' => $locked->ends_at,
                'old_staff_id' => $locked->staff_id,
                'new_starts_at' => $service->start,
                'new_ends_at' => $service->end,
                'new_staff_id' => $staffId,
                'actor_user_id' => $actor?->getKey(),
                'actor_type' => $actorType->value,
                'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            ]);

            $locked->forceFill([
                'staff_id' => $staffId,
                'starts_at' => $service->start,
                'ends_at' => $service->end,
                'blocked_starts_at' => $blocked->start,
                'blocked_ends_at' => $blocked->end,
                'reminder_sent_at' => null,
            ])->save();

            $this->notifier->rescheduledAfterCommit($locked, $history);

            return $locked;
        }, config('abs.transaction_attempts', 3));

        $appointment->setRawAttributes($updated->getAttributes(), true);

        return $appointment;
    }

    public function assertCustomerMayReschedule(Appointment $appointment, ?CarbonImmutable $newStart = null): void
    {
        $settings = $appointment->business->settings();

        if (! $settings->allow_customer_reschedule) {
            throw new BookingException('Online rescheduling is not available for this business. Please contact them.');
        }

        if (CarbonImmutable::now()->addMinutes($settings->reschedule_notice_minutes)->greaterThan($appointment->starts_at)) {
            throw new BookingException('It is too late to reschedule this appointment online.');
        }

        if ($newStart && ! BookingWindow::for($settings)->allows($newStart)) {
            throw new BookingException('That time is outside the booking window for this business.');
        }
    }
}
