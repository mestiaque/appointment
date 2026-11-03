<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use ME\Abs\Livewire\ManualBooking;
use ME\Abs\Models\Appointment;
use ME\Abs\Tests\TestCase;

class ManualBookingTest extends TestCase
{
    public function test_explains_why_there_are_no_times_and_offers_the_next_free_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 08:00:00'); // Sunday
        [$business, $service, $staff] = $this->bookableBusiness();
        $staff->schedules()->where('weekday', 0)->delete();
        $onlyHere = $business->branches()->create(['name' => 'Main']);
        $elsewhere = $business->branches()->create(['name' => 'City']);
        $staff->branches()->attach($onlyHere->id);
        $this->actingInBusiness($this->member($business), $business);

        Livewire::test(ManualBooking::class)
            ->set('serviceId', $service->id)
            ->set('branchId', $elsewhere->id)
            ->assertSee('No active staff member offers this service at this branch')
            ->set('branchId', $onlyHere->id)
            ->assertSee('Next free day')
            ->call('useDate', '2026-10-26')
            ->assertSet('date', '2026-10-26')
            ->assertSee('09:00 AM');
    }

    public function test_staff_creates_a_booking_and_missing_time_has_a_clear_message(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 06:00:00');
        [$business, $service] = $this->bookableBusiness();
        $this->actingInBusiness($this->member($business), $business);

        $component = Livewire::test(ManualBooking::class)
            ->set('serviceId', $service->id)->set('name', 'Walk In')->set('email', 'walkin@example.com')
            ->call('save')
            ->assertHasErrors(['selectedSlot']);
        $this->assertSame('Please pick a time.', $component->errors()->first('selectedSlot'));

        $component->set('selectedSlot', '2026-10-26T10:00:00Z')->call('save')->assertHasNoErrors();
        $this->assertSame('admin', Appointment::query()->firstOrFail()->source);
    }
}
