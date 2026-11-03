<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Booking\BookingEligibilityService;
use ME\Abs\Services\Booking\BookingWindow;
use ME\Abs\Services\Booking\SlotGenerator;
use ME\Abs\Tests\TestCase;

class SlotGenerationTest extends TestCase
{
    private const DATE = '2026-11-10'; // a Tuesday

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
    }

    /**
     * @return array<int, string> local "H:i" starts
     */
    private function slots(Business $business, Service $service, string $date = self::DATE, ?BookingWindow $window = null, ?int $branchId = null): array
    {
        $offers = app(BookingEligibilityService::class)->eligibleOffers($business, $service, null);
        $slots = app(SlotGenerator::class)->forDate($business->fresh(), $offers, $date, $branchId, $window);

        return array_map(fn ($iso) => CarbonImmutable::parse($iso)->setTimezone($business->timezone)->format('H:i'), array_keys($slots));
    }

    public function test_slots_fill_working_hours_at_the_slot_interval(): void
    {
        [$business, $service] = $this->bookableBusiness(['slot_interval_minutes' => 30], [], '09:00', '11:00');

        $this->assertSame(['09:00', '09:30', '10:00', '10:30'], $this->slots($business, $service));
    }

    public function test_service_must_fit_entirely_inside_working_hours(): void
    {
        [$business, $service] = $this->bookableBusiness(['slot_interval_minutes' => 30], [], '09:00', '11:00');
        $service->update(['duration_minutes' => 45]);

        $this->assertSame(['09:00', '09:30', '10:00'], $this->slots($business, $service));
    }

    public function test_multiple_intervals_per_day_and_breaks(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 30], [], '09:00', '10:00');
        $staff->schedules()->create(['weekday' => 2, 'start_time' => '14:00', 'end_time' => '15:00']);
        $staff->breaks()->create(['weekday' => 2, 'start_time' => '09:30', 'end_time' => '10:00']);

        $this->assertSame(['09:00', '14:00', '14:30'], $this->slots($business, $service));
    }

    public function test_time_off_and_holidays_remove_slots(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60], [], '09:00', '12:00');
        $staff->timeOff()->create(['starts_at' => '2026-11-10 10:00:00', 'ends_at' => '2026-11-10 11:00:00']);

        $this->assertSame(['09:00', '11:00'], $this->slots($business, $service));

        $business->holidays()->create(['date' => self::DATE, 'name' => 'Closed']);
        $this->assertSame([], $this->slots($business, $service));
        $this->assertNotSame([], $this->slots($business, $service, '2026-11-11'));
    }

    public function test_existing_appointments_and_buffers_block_slots(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 30], [], '09:00', '12:00');
        $service->update(['buffer_after_minutes' => 15]);

        // Existing booking 10:00-10:30 plus its 15 min buffer -> blocked until 10:45.
        Appointment::factory()->at($this->utc('2026-11-10 10:00'))->create([
            'staff_id' => $staff->id, 'business_id' => $business->id, 'blocked_ends_at' => $this->utc('2026-11-10 10:45'),
        ]);

        // 09:30 candidate is blocked until 10:15 (30 min + 15 buffer) -> overlaps 10:00. 11:00 is the next free.
        $this->assertSame(['09:00', '11:00', '11:30'], $this->slots($business, $service));
    }

    public function test_cancelled_and_expired_bookings_do_not_block_but_pending_follows_the_setting(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60, 'pending_blocks_slot' => true], [], '09:00', '11:00');
        $make = fn (AppointmentStatus $status, string $time, array $extra = []) => Appointment::factory()->status($status)
            ->at($this->utc("2026-11-10 {$time}"), 60)->create(['staff_id' => $staff->id, 'business_id' => $business->id] + $extra);

        $make(AppointmentStatus::Cancelled, '09:00');
        $make(AppointmentStatus::AwaitingPayment, '09:00', ['hold_expires_at' => $this->utc('2026-10-31 23:00')]); // expired hold
        $make(AppointmentStatus::Pending, '10:00');

        $this->assertSame(['09:00'], $this->slots($business, $service));

        $business->bookingSetting->update(['pending_blocks_slot' => false]);
        $this->assertSame(['09:00', '10:00'], $this->slots($business, $service));
    }

    public function test_active_hold_blocks_slot(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60], [], '09:00', '10:00');
        Appointment::factory()->status(AppointmentStatus::AwaitingPayment)->at($this->utc('2026-11-10 09:00'), 60)
            ->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'hold_expires_at' => $this->utc('2026-11-01 00:10')]);

        $this->assertSame([], $this->slots($business, $service));
    }

    public function test_notice_and_horizon_window(): void
    {
        [$business, $service] = $this->bookableBusiness(['slot_interval_minutes' => 60], [], '09:00', '13:00');
        CarbonImmutable::setTestNow('2026-11-10 09:30:00');
        $business->bookingSetting->update(['min_notice_minutes' => 90, 'max_days_ahead' => 1]);
        $window = BookingWindow::for($business->bookingSetting->fresh());

        $this->assertSame(['11:00', '12:00'], $this->slots($business, $service, self::DATE, $window));
        $this->assertSame([], $this->slots($business, $service, '2026-11-12', $window));
    }

    public function test_schedules_use_the_business_timezone_not_the_server_timezone(): void
    {
        [$business, $service] = $this->bookableBusiness(['slot_interval_minutes' => 60], ['timezone' => 'Asia/Dhaka'], '09:00', '11:00');

        $offers = app(BookingEligibilityService::class)->eligibleOffers($business, $service, null);
        $slots = array_keys(app(SlotGenerator::class)->forDate($business, $offers, self::DATE));

        $this->assertSame(['2026-11-10T03:00:00Z', '2026-11-10T04:00:00Z'], $slots);
    }

    public function test_dst_gap_day_skips_nonexistent_slots(): void
    {
        // 2026-03-08 is a Sunday; 02:00-03:00 does not exist in New York.
        [$business, $service] = $this->bookableBusiness(['slot_interval_minutes' => 30], ['timezone' => 'America/New_York'], '01:00', '04:00');
        CarbonImmutable::setTestNow('2026-03-01 00:00:00');

        $this->assertSame(['01:00', '01:30', '03:00', '03:30'], $this->slots($business, $service, '2026-03-08'));
    }

    public function test_booking_can_cross_midnight_across_consecutive_intervals(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60], [], '22:00', '24:00');
        foreach (range(0, 6) as $weekday) {
            $staff->schedules()->create(['weekday' => $weekday, 'start_time' => '00:00', 'end_time' => '02:00']);
        }
        $service->update(['duration_minutes' => 180]);

        // New Year's Eve 22:00 -> 01:00 on Jan 1: crosses midnight, month and year boundaries.
        $this->assertSame(['22:00', '23:00'], $this->slots($business, $service, '2026-12-31'));

        // Without the after-midnight interval the shift ends at 24:00 and a 3-hour service no longer fits.
        $staff->schedules()->where('start_time', '00:00:00')->delete();
        $this->assertSame([], $this->slots($business, $service, '2026-12-31'));
    }

    public function test_staff_price_and_duration_overrides(): void
    {
        $business = Business::factory()->withSettings(['min_notice_minutes' => 0, 'slot_interval_minutes' => 60])->create();
        $service = Service::factory()->create(['business_id' => $business->id, 'duration_minutes' => 30, 'price_minor' => 5000]);
        Staff::factory()->workingHours('09:00', '11:00')->offering($service, 9000, 90)->create(['business_id' => $business->id]);

        $offer = app(BookingEligibilityService::class)->eligibleOffers($business, $service, null)->first();
        $this->assertSame(90, $offer->durationMinutes);
        $this->assertSame(9000, $offer->unitPriceMinor);
        $this->assertSame(['09:00'], $this->slots($business, $service));
    }

    public function test_inactive_or_non_bookable_staff_and_other_business_staff_are_not_offered(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $other = Business::factory()->create();
        Staff::factory()->workingHours()->offering($service)->create(['business_id' => $other->id]);
        Staff::factory()->workingHours()->offering($service)->create(['business_id' => $business->id, 'accepts_bookings' => false]);

        $offers = app(BookingEligibilityService::class)->eligibleOffers($business, $service, null);

        $this->assertSame([$staff->id], $offers->map(fn ($o) => $o->staff->id)->all());
    }
}
