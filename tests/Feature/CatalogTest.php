<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Livewire\ScheduleEditor;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_service_crud_stores_money_in_minor_units(): void
    {
        $business = Business::factory()->create(['currency' => 'JPY']);
        $owner = $this->member($business);
        $category = $business->serviceCategories()->create(['name' => 'Cut']);

        $this->actingInBusiness($owner, $business)->post(route('abs.services.store'), [
            'name' => 'Haircut', 'category_id' => $category->id, 'duration_minutes' => 45, 'price' => '3,500',
            'buffer_after_minutes' => 10, 'deposit_type' => 'percent', 'deposit_value' => '20', 'is_active' => 1,
        ])->assertRedirect(route('abs.services.index'));

        $service = Service::query()->where('name', 'Haircut')->firstOrFail();
        $this->assertSame(3500, $service->price_minor);
        $this->assertSame(20, $service->deposit_value);
        $this->assertTrue($service->is_active);

        $this->put(route('abs.services.update', $service->id), ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => '12.5', 'deposit_type' => 'none'])
            ->assertSessionHasErrors('price'); // JPY has no decimals

        $this->delete(route('abs.services.destroy', $service->id))->assertRedirect();
        $this->assertSoftDeleted($service);
    }

    public function test_staff_is_created_with_service_overrides_and_branches(): void
    {
        [$business, $service] = $this->bookableBusiness();
        $owner = $this->member($business);
        $branch = $business->branches()->create(['name' => 'North']);

        $this->actingInBusiness($owner, $business)->post(route('abs.staff.store'), [
            'name' => 'Dr. Rahman', 'is_active' => 1, 'accepts_bookings' => 1,
            'services' => [['id' => $service->id, 'enabled' => 1, 'price' => '75.00', 'duration_minutes' => 45]],
            'branches' => [$branch->id],
        ])->assertRedirect();

        $staff = $business->staff()->where('name', 'Dr. Rahman')->firstOrFail();
        $pivot = $staff->services()->first()->pivot;
        $this->assertSame(7500, (int) $pivot->price_minor);
        $this->assertSame(45, (int) $pivot->duration_minutes);
        $this->assertSame([$branch->id], $staff->branches()->pluck('abs_branches.id')->all());
    }

    public function test_schedule_editor_saves_multiple_intervals_and_rejects_overlaps(): void
    {
        [$business, , $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        $this->actingInBusiness($owner, $business);

        Livewire::test(ScheduleEditor::class, ['staff' => $staff])
            ->set('work.1', [['start' => '09:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '15:00']])
            ->call('save')
            ->assertHasErrors('work.1');

        Livewire::test(ScheduleEditor::class, ['staff' => $staff])
            ->set('work.1', [['start' => '09:00', 'end' => '08:00']])
            ->call('save')
            ->assertHasErrors('work.1');

        Livewire::test(ScheduleEditor::class, ['staff' => $staff])
            ->set('work.1', [['start' => '25:00', 'end' => '26:00']])
            ->call('save')
            ->assertHasErrors();

        Livewire::test(ScheduleEditor::class, ['staff' => $staff])
            ->set('work', [1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '24:00']], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 0 => []])
            ->set('breaks', [1 => [['start' => '10:00', 'end' => '10:15']], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 0 => []])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $staff->schedules()->count());
        $this->assertSame('24:00:00', $staff->schedules()->where('start_time', '13:00:00')->value('end_time'));
        $this->assertSame(1, $staff->breaks()->count());
    }

    public function test_time_off_is_stored_in_utc_from_business_local_time(): void
    {
        [$business, , $staff] = $this->bookableBusiness([], ['timezone' => 'Asia/Dhaka']);
        $owner = $this->member($business);

        $this->actingInBusiness($owner, $business)->post(route('abs.staff.time-off.store', $staff->id), [
            'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-01T17:00', 'reason' => 'Training',
        ])->assertRedirect();

        $off = $staff->timeOff()->first();
        $this->assertSame('2026-12-01 03:00', $off->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 11:00', $off->ends_at->format('Y-m-d H:i'));
    }

    public function test_booking_settings_update(): void
    {
        [$business] = $this->bookableBusiness();
        $owner = $this->member($business);

        $this->actingInBusiness($owner, $business)->put(route('abs.settings.update'), [
            'approval_mode' => 'approval', 'payment_mode' => 'pay_later', 'min_notice_minutes' => 120, 'max_days_ahead' => 30,
            'slot_interval_minutes' => 30, 'cancellation_notice_minutes' => 60, 'reschedule_notice_minutes' => 60, 'hold_minutes' => 30,
            'tax_rate_percent' => '12.5', 'reminder_minutes_before' => 1440, 'allow_guest_booking' => 0, 'pending_blocks_slot' => 1,
        ])->assertSessionHasNoErrors();

        $settings = $business->bookingSetting()->first();
        $this->assertSame('approval', $settings->approval_mode->value);
        $this->assertSame(1250, $settings->tax_rate_bps);
        $this->assertFalse($settings->allow_guest_booking);
    }

    public function test_approving_a_request_rechecks_the_slot_when_pending_does_not_block(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        [$business, , $staff] = $this->bookableBusiness(['pending_blocks_slot' => false]);
        $make = fn (AppointmentStatus $status) => Appointment::factory()->status($status)->at($this->utc('2026-11-10 09:00'))
            ->create(['staff_id' => $staff->id, 'business_id' => $business->id]);

        $first = $make(AppointmentStatus::Pending);
        $second = $make(AppointmentStatus::Pending);
        $machine = app(AppointmentStateMachine::class);

        $machine->transition($first, AppointmentStatus::Confirmed, ActorType::Staff);

        $this->expectException(BookingException::class);
        try {
            $machine->transition($second, AppointmentStatus::Confirmed, ActorType::Staff);
        } finally {
            $this->assertSame(AppointmentStatus::Pending, $second->fresh()->status);
        }
    }
}
