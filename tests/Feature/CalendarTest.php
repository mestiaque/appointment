<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Livewire\ManualBooking;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;
use ME\Abs\Tests\TestCase;

class CalendarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
    }

    private function feed(array $query = [])
    {
        return $this->getJson(route('abs.calendar.events', ['start' => '2026-11-09T00:00:00Z', 'end' => '2026-11-16T00:00:00Z'] + $query));
    }

    public function test_feed_filters_by_staff_and_status_and_describes_actions(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $other = Staff::factory()->workingHours()->offering($service)->create(['business_id' => $business->id]);
        $owner = $this->member($business);
        $a = Appointment::factory()->at($this->utc('2026-11-10 09:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id]);
        Appointment::factory()->status(AppointmentStatus::Cancelled)->at($this->utc('2026-11-10 10:00'))->create(['staff_id' => $other->id, 'business_id' => $business->id]);

        $this->actingInBusiness($owner, $business);

        $this->feed()->assertOk()->assertJsonCount(2);
        $this->feed(['staff_ids' => [$staff->id]])->assertJsonCount(1)->assertJsonPath('0.id', $a->id);
        $this->feed(['statuses' => ['cancelled']])->assertJsonCount(1)->assertJsonPath('0.extendedProps.status', 'cancelled');

        $event = $this->feed(['staff_ids' => [$staff->id]])->json('0');
        $this->assertTrue($event['editable']);
        $this->assertSame(['cancelled'], array_column($event['extendedProps']['actions'], 'status')); // visit statuses wait for the day
        $this->assertNotSame($event['borderColor'], $this->feed(['staff_ids' => [$other->id]])->json('0.borderColor'));
    }

    public function test_drag_and_drop_moves_through_the_rescheduling_rules(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        $a = Appointment::factory()->at($this->utc('2026-11-10 09:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'service_id' => $service->id]);
        $a->items()->create(['service_id' => $service->id, 'service_name' => 'S', 'duration_minutes' => 30, 'unit_price_minor' => 5000, 'line_total_minor' => 5000]);
        Appointment::factory()->at($this->utc('2026-11-10 14:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id]);

        $this->actingInBusiness($owner, $business);

        $this->postJson(route('abs.calendar.move', $a->id), ['start' => '2026-11-10T11:00:00Z'])->assertOk();
        $this->assertSame('2026-11-10 11:00', $a->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('abs_appointment_reschedule_histories', ['appointment_id' => $a->id, 'reason' => 'Moved on calendar']);

        $this->postJson(route('abs.calendar.move', $a->id), ['start' => '2026-11-10T14:00:00Z'])->assertStatus(422)->assertJsonStructure(['message']);
        $this->postJson(route('abs.calendar.move', $a->id), ['start' => '2026-11-10T20:00:00Z'])->assertStatus(422); // outside hours
        $this->assertSame('2026-11-10 11:00', $a->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_move_needs_permission_and_ownership(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $a = Appointment::factory()->at($this->utc('2026-11-10 09:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id]);
        $staffUser = $this->member($business, BusinessRole::Staff);
        $staff->update(['user_id' => $staffUser->id]);

        $this->actingInBusiness($staffUser, $business)->postJson(route('abs.calendar.move', $a->id), ['start' => '2026-11-10T11:00:00Z'])->assertForbidden();
        $this->assertFalse($this->feed()->json('0.editable'));

        [$otherBusiness] = $this->bookableBusiness();
        $foreignOwner = $this->member($otherBusiness);
        $this->actingInBusiness($foreignOwner, $otherBusiness)->postJson(route('abs.calendar.move', $a->id), ['start' => '2026-11-10T11:00:00Z'])->assertNotFound();
        $this->assertSame('2026-11-10 09:00', $a->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_calendar_page_shows_staff_colours_and_working_hours(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness([], [], '09:00', '17:00');
        $staff->schedules()->where('weekday', 5)->delete(); // Friday off
        $owner = $this->member($business);

        $this->actingInBusiness($owner, $business)->get(route('abs.calendar'))
            ->assertOk()
            ->assertSee('abs-cal-chip', false)
            ->assertSee(e($staff->name), false)
            ->assertSee("hiddenDays: JSON.parse('[5]')", false)
            ->assertSee('Today\'s queue', false);
    }

    public function test_new_booking_is_prefilled_from_an_empty_calendar_slot(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        $this->actingInBusiness($owner, $business);

        Livewire::withQueryParams(['date' => '2026-11-12', 'staff' => $staff->id])
            ->test(ManualBooking::class)
            ->assertSet('date', '2026-11-12')
            ->assertSet('staffChoice', (string) $staff->id)
            ->set('serviceId', $service->id)
            ->assertSet('staffChoice', (string) $staff->id);
    }
}
