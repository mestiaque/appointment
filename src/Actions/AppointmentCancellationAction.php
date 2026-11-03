<?php

namespace ME\Abs\Actions;

use Carbon\CarbonImmutable;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Appointment;
use ME\Models\User;

/**
 * Cancels an appointment. Customers are limited by the business cancellation policy; staff are not.
 * Cancelling never refunds automatically — refunds are a separate, permission-checked action.
 */
class AppointmentCancellationAction
{
    public function __construct(private AppointmentStateMachine $stateMachine) {}

    public function execute(Appointment $appointment, ActorType $actorType, ?User $actor = null, ?string $reason = null): Appointment
    {
        if ($actorType === ActorType::Customer) {
            $this->assertCustomerMayCancel($appointment);
        }

        return $this->stateMachine->transition($appointment, AppointmentStatus::Cancelled, $actorType, $actor, $reason);
    }

    public function customerMayCancel(Appointment $appointment): bool
    {
        try {
            $this->assertCustomerMayCancel($appointment);

            return true;
        } catch (BookingException) {
            return false;
        }
    }

    public function assertCustomerMayCancel(Appointment $appointment): void
    {
        $settings = $appointment->business->settings();

        if (! $appointment->status->canTransitionTo(AppointmentStatus::Cancelled)) {
            throw new BookingException('This appointment can no longer be cancelled.');
        }

        if (! $settings->allow_customer_cancellation) {
            throw new BookingException('Online cancellation is not available for this business. Please contact them.');
        }

        if (CarbonImmutable::now()->addMinutes($settings->cancellation_notice_minutes)->greaterThan($appointment->starts_at)) {
            throw new BookingException('It is too late to cancel this appointment online.');
        }
    }
}
