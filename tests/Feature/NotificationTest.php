<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Actions\AppointmentCancellationAction;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\NotificationLog;
use ME\Abs\Notifications\AppointmentNotification;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Tests\TestCase;

class NotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
    }

    private function confirmed(array $settings = []): Appointment
    {
        [$business, $service, $staff] = $this->bookableBusiness($settings);

        return app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $business, $service->id, $staff->id, null, $this->utc('2026-11-02 09:00'), 'Mina', 'mina@example.com',
        ));
    }

    public function test_booking_mail_is_rendered_and_sent_once_to_customer_and_business(): void
    {
        Mail::fake();
        $appointment = $this->confirmed();

        $logs = NotificationLog::query()->where('appointment_id', $appointment->id)->get();
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn ($log) => $log->status === 'sent'));
        $this->assertTrue($logs->contains('recipient', 'mi***@example.com'));

        $notification = new AppointmentNotification($appointment->id, 'booking_confirmed', 'customer', 'x');
        $html = $notification->toMail(new AnonymousNotifiable)->render();
        $this->assertStringContainsString($appointment->booking_reference, (string) $html);
        $this->assertStringContainsString('View or change booking', (string) $html);
    }

    public function test_retried_notification_is_not_sent_twice(): void
    {
        $appointment = $this->confirmed();
        $notification = new AppointmentNotification($appointment->id, 'booking_confirmed', 'customer', "a{$appointment->id}:booking_confirmed:c:");

        // The first delivery already happened during booking (sync queue in tests).
        $this->assertFalse($notification->shouldSend(new AnonymousNotifiable, 'mail'));
    }

    public function test_failed_delivery_is_logged_and_can_be_retried(): void
    {
        Notification::fake();
        $appointment = $this->confirmed();
        $notification = new AppointmentNotification($appointment->id, 'booking_confirmed', 'customer', 'retry-key');

        $this->assertTrue($notification->shouldSend(new AnonymousNotifiable, 'mail'));
        $notification->failed(new \RuntimeException('SMTP down'));

        $log = NotificationLog::query()->where('dedupe_key', 'retry-key:mail')->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('SMTP down', $log->error);
        $this->assertTrue($notification->shouldSend(new AnonymousNotifiable, 'mail'));
    }

    public function test_rolled_back_booking_sends_nothing(): void
    {
        Notification::fake();
        [$business, $service, $staff] = $this->bookableBusiness();

        try {
            DB::transaction(function () use ($business, $service, $staff) {
                app(AppointmentBookingAction::class)->execute(new BookingRequest($business, $service->id, $staff->id, null, $this->utc('2026-11-02 09:00'), 'X', 'x@example.com'));
                throw new \RuntimeException('outer failure');
            });
        } catch (\RuntimeException) {
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('abs_appointments', 0);
    }

    public function test_reminders_are_sent_once_inside_the_window(): void
    {
        $appointment = $this->confirmed(['reminder_minutes_before' => 60 * 24]);
        Notification::fake(); // only count notifications sent from here on
        $later = Appointment::factory()->at($this->utc('2026-11-05 09:00'))->create(['staff_id' => $appointment->staff_id, 'business_id' => $appointment->business_id]);

        CarbonImmutable::setTestNow('2026-11-01 08:59:00');
        $this->artisan('abs:send-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        CarbonImmutable::setTestNow('2026-11-01 09:05:00');
        $this->artisan('abs:send-reminders')->assertSuccessful();
        $this->artisan('abs:send-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(AppointmentNotification::class, 1);
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
        $this->assertNull($later->fresh()->reminder_sent_at);
    }

    public function test_cancelled_appointments_get_no_reminder(): void
    {
        Notification::fake();
        $appointment = $this->confirmed(['reminder_minutes_before' => 60 * 48, 'cancellation_notice_minutes' => 0]);
        app(AppointmentCancellationAction::class)->execute($appointment, ActorType::Staff);
        Notification::fake();

        $this->artisan('abs:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }
}
