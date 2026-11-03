<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Payments\PaymentLedger;
use ME\Abs\Tests\TestCase;

class DiscountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
    }

    private function booked(): Appointment
    {
        [$business, $service, $staff] = $this->bookableBusiness(['tax_rate_bps' => 1000]); // 10 %

        return app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $business, $service->id, $staff->id, null, $this->utc('2026-11-10 09:00'), 'Lina', 'lina@example.com',
        ));
    }

    public function test_discount_reprices_with_the_booking_time_tax_rate(): void
    {
        $appointment = $this->booked();                             // 5000 + 500 tax = 5500
        $appointment->business->bookingSetting->update(['tax_rate_bps' => 2500]); // later change must not matter
        $owner = $this->member($appointment->business);

        $this->actingInBusiness($owner, $appointment->business)
            ->put(route('abs.appointments.discount', $appointment->id), ['discount' => '10.00'])
            ->assertSessionHasNoErrors();

        $fresh = $appointment->fresh();
        $this->assertSame(1000, $fresh->discount_minor);
        $this->assertSame(400, $fresh->tax_minor);                 // 10 % of 4000
        $this->assertSame(4400, $fresh->total_minor);
    }

    public function test_discount_cannot_exceed_subtotal_or_go_below_collected(): void
    {
        $appointment = $this->booked();
        $owner = $this->member($appointment->business);
        app(PaymentLedger::class)->recordManual($appointment, 5000, 'cash', $owner);
        $this->actingInBusiness($owner, $appointment->business);

        $this->put(route('abs.appointments.discount', $appointment->id), ['discount' => '60.00'])->assertSessionHasErrors('discount');
        $this->put(route('abs.appointments.discount', $appointment->id), ['discount' => '20.00'])->assertSessionHasErrors('discount'); // total 3300 < paid 5000

        $this->put(route('abs.appointments.discount', $appointment->id), ['discount' => '0.50'])->assertSessionHasNoErrors();
        $fresh = $appointment->fresh();
        $this->assertSame(5445, $fresh->total_minor);              // 4950 + 495
        $this->assertSame(PaymentStatus::PartiallyPaid, $fresh->payment_status);
    }

    public function test_discount_needs_edit_permission(): void
    {
        $appointment = $this->booked();
        $staffUser = $this->member($appointment->business, BusinessRole::Staff);
        $appointment->staff->update(['user_id' => $staffUser->id]);

        $this->actingInBusiness($staffUser, $appointment->business)
            ->put(route('abs.appointments.discount', $appointment->id), ['discount' => '10'])
            ->assertForbidden();
        $this->assertSame(0, $appointment->fresh()->discount_minor);
    }

    public function test_appointment_page_lists_the_customers_other_bookings_in_this_business_only(): void
    {
        $appointment = $this->booked();
        $owner = $this->member($appointment->business);
        $earlier = Appointment::factory()->at($this->utc('2026-10-01 09:00'))->create([
            'staff_id' => $appointment->staff_id, 'business_id' => $appointment->business_id, 'customer_email' => 'lina@example.com',
        ]);
        [$other] = $this->bookableBusiness();
        $foreign = Appointment::factory()->create([
            'staff_id' => Staff::factory()->create(['business_id' => $other->id])->id, 'business_id' => $other->id, 'customer_email' => 'lina@example.com',
        ]);

        $this->actingInBusiness($owner, $appointment->business)
            ->get(route('abs.appointments.show', $appointment->id))
            ->assertOk()
            ->assertSee(route('abs.appointments.show', $earlier->id))
            ->assertDontSee(route('abs.appointments.show', $foreign->id));
    }
}
