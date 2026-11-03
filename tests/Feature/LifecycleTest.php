<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Actions\AppointmentCancellationAction;
use ME\Abs\Actions\AppointmentReschedulingAction;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Exceptions\InvalidTransitionException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;
use ME\Abs\Notifications\AppointmentNotification;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Tests\TestCase;

class LifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
    }

    private function booked(array $settings = []): Appointment
    {
        [$business, $service, $staff] = $this->bookableBusiness($settings);

        return app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $business, $service->id, $staff->id, null, $this->utc('2026-11-10 09:00'), 'Karim', 'karim@example.com',
        ));
    }

    public function test_full_visit_lifecycle_is_recorded(): void
    {
        $appointment = $this->booked();
        $machine = app(AppointmentStateMachine::class);
        $actor = $this->member($appointment->business);
        CarbonImmutable::setTestNow('2026-11-10 09:05:00');

        foreach ([AppointmentStatus::CheckedIn, AppointmentStatus::InProgress, AppointmentStatus::Completed] as $status) {
            $machine->transition($appointment, $status, ActorType::Staff, $actor);
        }

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->assertSame(
            ['confirmed', 'checked_in', 'in_progress', 'completed'],
            $appointment->statusHistories()->pluck('to_status')->map(fn ($s) => $s->value)->all(),
        );
        $this->assertSame($actor->id, $appointment->statusHistories()->reorder('id', 'desc')->first()->actor_user_id);
    }

    public function test_visit_statuses_cannot_be_set_before_their_time(): void
    {
        $appointment = $this->booked(); // 2026-11-10 09:00, now 2026-11-01
        $machine = app(AppointmentStateMachine::class);

        foreach ([AppointmentStatus::NoShow, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress] as $status) {
            try {
                $machine->transition($appointment, $status, ActorType::Staff);
                $this->fail("{$status->value} allowed before its time");
            } catch (BookingException) {
                $this->addToAssertionCount(1);
            }
        }

        CarbonImmutable::setTestNow('2026-11-10 07:00:00'); // same day, before start
        $machine->transition($appointment, AppointmentStatus::CheckedIn, ActorType::Staff);
        $machine->transition($appointment, AppointmentStatus::InProgress, ActorType::Staff);

        $this->expectException(BookingException::class);
        $machine->transition($appointment, AppointmentStatus::Completed, ActorType::Staff);
    }

    public function test_invalid_transition_is_refused_and_not_recorded(): void
    {
        $appointment = $this->booked();

        $this->expectException(InvalidTransitionException::class);

        try {
            app(AppointmentStateMachine::class)->transition($appointment, AppointmentStatus::Completed, ActorType::Staff);
        } finally {
            $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
            $this->assertSame(1, $appointment->statusHistories()->count());
        }
    }

    public function test_status_is_not_mass_assignable(): void
    {
        $appointment = $this->booked();
        $appointment->update(['status' => 'completed', 'payment_status' => 'paid', 'paid_minor' => 5000]);

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Confirmed, $fresh->status);
        $this->assertSame(0, $fresh->paid_minor);
    }

    public function test_owner_approval_and_rejection(): void
    {
        $approved = $this->booked(['approval_mode' => 'approval']);
        app(AppointmentStateMachine::class)->transition($approved, AppointmentStatus::Confirmed, ActorType::Staff);
        $this->assertNotNull($approved->fresh()->confirmed_at);
        Notification::assertSentOnDemand(AppointmentNotification::class, fn ($n) => $n->event === 'booking_approved');

        $rejected = $this->booked(['approval_mode' => 'approval']);
        app(AppointmentStateMachine::class)->transition($rejected, AppointmentStatus::Rejected, ActorType::Staff, null, 'Fully booked that day');
        $this->assertSame('Fully booked that day', $rejected->fresh()->cancellation_reason);
        Notification::assertSentOnDemand(AppointmentNotification::class, fn ($n) => $n->event === 'booking_rejected');
    }

    public function test_customer_cancellation_follows_the_notice_policy(): void
    {
        $appointment = $this->booked(['cancellation_notice_minutes' => 60 * 24 * 30]);
        $action = app(AppointmentCancellationAction::class);

        $this->assertFalse($action->customerMayCancel($appointment));

        try {
            $action->execute($appointment, ActorType::Customer);
            $this->fail('Customer cancelled inside the notice period.');
        } catch (BookingException) {
            $this->addToAssertionCount(1);
        }

        // Staff are not bound by the customer policy.
        $action->execute($appointment, ActorType::Staff, null, 'Staff sick');
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_cancelled_slot_becomes_available_again(): void
    {
        $appointment = $this->booked(['cancellation_notice_minutes' => 0]);
        app(AppointmentCancellationAction::class)->execute($appointment, ActorType::Customer);

        $again = app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $appointment->business, $appointment->service_id, $appointment->staff_id, null, $this->utc('2026-11-10 09:00'), 'Next', 'next@example.com',
        ));

        $this->assertSame(AppointmentStatus::Confirmed, $again->status);
    }

    public function test_reschedule_moves_the_booking_and_keeps_history(): void
    {
        $appointment = $this->booked(['reschedule_notice_minutes' => 0]);

        app(AppointmentReschedulingAction::class)->execute($appointment, $this->utc('2026-11-11 14:00'), ActorType::Customer, null, null, 'Clash');

        $fresh = $appointment->fresh();
        $this->assertSame('2026-11-11 14:00', $fresh->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 14:30', $fresh->ends_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('abs_appointment_reschedule_histories', ['appointment_id' => $appointment->id, 'reason' => 'Clash']);
        Notification::assertSentOnDemand(AppointmentNotification::class, fn ($n) => $n->event === 'appointment_rescheduled');
    }

    public function test_reschedule_into_a_taken_slot_keeps_the_original_booking(): void
    {
        $appointment = $this->booked(['reschedule_notice_minutes' => 0]);
        Appointment::factory()->at($this->utc('2026-11-12 10:00'))->create(['staff_id' => $appointment->staff_id, 'business_id' => $appointment->business_id]);

        try {
            app(AppointmentReschedulingAction::class)->execute($appointment, $this->utc('2026-11-12 10:00'), ActorType::Staff);
            $this->fail('Rescheduled into a conflict.');
        } catch (BookingException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('2026-11-10 09:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertDatabaseCount('abs_appointment_reschedule_histories', 0);
    }

    public function test_reschedule_to_an_overlapping_time_of_itself_is_allowed(): void
    {
        $appointment = $this->booked(['reschedule_notice_minutes' => 0]);

        app(AppointmentReschedulingAction::class)->execute($appointment, $this->utc('2026-11-10 09:15'), ActorType::Staff);

        $this->assertSame('2026-11-10 09:15', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_reschedule_to_another_staff_member(): void
    {
        $appointment = $this->booked();
        $other = Staff::factory()->workingHours()->offering($appointment->service)->create(['business_id' => $appointment->business_id]);

        app(AppointmentReschedulingAction::class)->execute($appointment, $this->utc('2026-11-10 09:00'), ActorType::Staff, null, $other->id);

        $this->assertSame($other->id, $appointment->fresh()->staff_id);
    }

    public function test_customer_reschedule_policy(): void
    {
        $appointment = $this->booked(['allow_customer_reschedule' => false]);

        $this->expectException(BookingException::class);
        app(AppointmentReschedulingAction::class)->execute($appointment, $this->utc('2026-11-11 10:00'), ActorType::Customer);
    }
}
