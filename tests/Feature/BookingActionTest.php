<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;
use ME\Abs\Notifications\AppointmentNotification;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Tests\TestCase;

class BookingActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
    }

    private function request(Business $business, Service $service, ?Staff $staff, string $start = '2026-11-10 09:00', array $overrides = []): BookingRequest
    {
        return new BookingRequest(...array_merge([
            'business' => $business,
            'serviceId' => $service->id,
            'staffId' => $staff?->id,
            'branchId' => null,
            'startsAt' => $this->utc($start),
            'customerName' => 'Rahim Uddin',
            'customerEmail' => 'Rahim@Example.com',
            'customerPhone' => '01700000000',
        ], $overrides));
    }

    private function book(BookingRequest $request)
    {
        return app(AppointmentBookingAction::class)->execute($request);
    }

    public function test_instant_booking_is_confirmed_with_price_snapshot_and_history(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['tax_rate_bps' => 1000]);

        $appointment = $this->book($this->request($business, $service, $staff));

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(PaymentStatus::Unpaid, $appointment->fresh()->payment_status);
        $this->assertMatchesRegularExpression('/^BK-[0-9A-HJKMNP-TV-Z]{8}$/', $appointment->booking_reference);
        $this->assertSame('rahim@example.com', $appointment->customer_email);
        $this->assertSame(5000, $appointment->subtotal_minor);
        $this->assertSame(500, $appointment->tax_minor);
        $this->assertSame(5500, $appointment->total_minor);
        $this->assertSame('2026-11-10 09:30:00', $appointment->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('abs_appointment_items', ['appointment_id' => $appointment->id, 'service_name' => $service->name, 'unit_price_minor' => 5000, 'duration_minutes' => 30]);
        $this->assertDatabaseHas('abs_appointment_status_histories', ['appointment_id' => $appointment->id, 'from_status' => null, 'to_status' => 'confirmed', 'actor_type' => 'customer']);

        Notification::assertSentOnDemand(AppointmentNotification::class, fn ($n) => $n->event === 'booking_confirmed' && $n->audience === 'customer');
    }

    public function test_price_changes_after_booking_do_not_change_the_snapshot(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = $this->book($this->request($business, $service, $staff));

        $service->update(['price_minor' => 99999, 'duration_minutes' => 90, 'name' => 'Renamed']);

        $item = $appointment->fresh()->items->first();
        $this->assertSame(5000, $item->unit_price_minor);
        $this->assertSame(30, $item->duration_minutes);
        $this->assertNotSame('Renamed', $item->service_name);
        $this->assertSame(5000, $appointment->fresh()->total_minor);
    }

    public function test_approval_mode_creates_pending_booking(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['approval_mode' => 'approval']);

        $appointment = $this->book($this->request($business, $service, $staff));

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        Notification::assertSentOnDemand(AppointmentNotification::class, fn ($n) => $n->event === 'booking_received');
    }

    public function test_same_slot_cannot_be_booked_twice_for_the_same_staff(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $this->book($this->request($business, $service, $staff));

        $this->expectException(BookingException::class);
        $this->book($this->request($business, $service, $staff, '2026-11-10 09:15'));
    }

    public function test_any_staff_picks_the_next_free_staff_member(): void
    {
        [$business, $service, $first] = $this->bookableBusiness();
        $second = Staff::factory()->workingHours()->offering($service)->create(['business_id' => $business->id]);

        $a = $this->book($this->request($business, $service, null));
        $b = $this->book($this->request($business, $service, null));

        $this->assertSame($first->id, $a->staff_id);
        $this->assertSame($second->id, $b->staff_id);

        $this->expectException(BookingException::class);
        $this->book($this->request($business, $service, null));
    }

    public function test_service_or_staff_from_another_business_is_rejected(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        [$otherBusiness, $otherService, $otherStaff] = $this->bookableBusiness();

        foreach ([[$otherService, $staff], [$service, $otherStaff]] as [$tamperedService, $tamperedStaff]) {
            try {
                $this->book($this->request($business, $tamperedService, $tamperedStaff));
                $this->fail('Booking with a foreign id succeeded.');
            } catch (BookingException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('abs_appointments', 0);
    }

    public function test_branch_from_another_business_is_rejected(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $foreignBranch = Business::factory()->create()->branches()->create(['name' => 'Elsewhere']);

        $this->expectException(BookingException::class);
        $this->book($this->request($business, $service, $staff, overrides: ['branchId' => $foreignBranch->id]));
    }

    public function test_inactive_business_and_service_cannot_be_booked(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $service->update(['is_active' => false]);

        try {
            $this->book($this->request($business, $service, $staff));
            $this->fail('Inactive service booked.');
        } catch (BookingException) {
            $this->addToAssertionCount(1);
        }

        $service->update(['is_active' => true]);
        $business->update(['status' => 'inactive']);

        $this->expectException(BookingException::class);
        $this->book($this->request($business->fresh(), $service, $staff));
    }

    public function test_online_booking_respects_notice_and_horizon_but_staff_booking_does_not(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['min_notice_minutes' => 60 * 24 * 30]);

        try {
            $this->book($this->request($business, $service, $staff));
            $this->fail('Booked inside the notice period.');
        } catch (BookingException) {
            $this->addToAssertionCount(1);
        }

        $appointment = $this->book($this->request($business, $service, $staff, overrides: ['source' => 'admin']));
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
    }

    public function test_guest_booking_can_be_disabled(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['allow_guest_booking' => false]);

        $this->expectException(BookingException::class);
        $this->book($this->request($business, $service, $staff));
    }

    public function test_required_online_payment_creates_a_hold(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['payment_mode' => 'deposit_required', 'hold_minutes' => 30]);
        $service->update(['deposit_type' => 'percent', 'deposit_value' => 20]);

        $appointment = $this->book($this->request($business, $service, $staff));

        $this->assertSame(AppointmentStatus::AwaitingPayment, $appointment->status);
        $this->assertSame(1000, $appointment->deposit_minor);
        $this->assertSame('2026-11-01 00:30:00', $appointment->fresh()->hold_expires_at->format('Y-m-d H:i:s'));
        Notification::assertNothingSent();
    }

    public function test_slot_outside_working_hours_is_rejected(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();

        $this->expectException(BookingException::class);
        $this->book($this->request($business, $service, $staff, '2026-11-10 16:45'));
    }
}
