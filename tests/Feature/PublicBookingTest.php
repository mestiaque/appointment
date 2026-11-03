<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Livewire\BookingWizard;
use ME\Abs\Livewire\RescheduleForm;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Service;
use ME\Abs\Tests\TestCase;

class PublicBookingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
    }

    public function test_booking_page_renders_for_active_business_only(): void
    {
        [$business, $service] = $this->bookableBusiness();

        $this->get(route('abs.public.show', $business->slug))->assertOk()->assertSee(e($service->name), false);

        $business->update(['status' => 'inactive']);
        $this->get(route('abs.public.show', $business->slug))->assertNotFound();
    }

    public function test_guest_completes_the_wizard(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60]);

        $wizard = Livewire::test(BookingWizard::class, ['business' => $business])
            ->assertSet('step', 2)
            ->call('chooseService', $service->id)
            ->call('chooseStaff', (string) $staff->id)
            ->call('chooseDate', '2026-11-02')
            ->call('chooseSlot', '2026-11-02T09:00:00Z')
            ->assertSet('step', 5)
            ->set('name', 'Guest Person')
            ->set('email', 'guest@example.com')
            ->call('toSummary')
            ->assertSet('step', 6)
            ->call('book');

        $appointment = Appointment::query()->firstOrFail();
        $wizard->assertRedirect($appointment->manageUrl());
        $this->assertSame('2026-11-02 09:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertNull($appointment->customer_user_id);

        // A second submit of the same component does not create another booking.
        $wizard->call('book');
        $this->assertDatabaseCount('abs_appointments', 1);
    }

    public function test_registered_customer_booking_is_linked_and_listed(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60]);
        $customer = UserFactory::new()->create();

        Livewire::actingAs($customer)->test(BookingWizard::class, ['business' => $business])
            ->assertSet('email', $customer->email)
            ->call('chooseService', $service->id)->call('chooseStaff', 'any')
            ->call('chooseDate', '2026-11-02')->call('chooseSlot', '2026-11-02T10:00:00Z')
            ->call('toSummary')->call('book');

        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame($customer->id, $appointment->customer_user_id);
        $this->actingAs($customer)->get(route('abs.public.mine'))->assertRedirect(route('abs.account.dashboard'));
        $this->get(route('abs.account.dashboard'))->assertOk()->assertSee(e($appointment->items->first()->service_name), false);
    }

    public function test_wizard_ignores_tampered_choices(): void
    {
        [$business, $service] = $this->bookableBusiness();
        [, $foreignService, $foreignStaff] = $this->bookableBusiness();

        Livewire::test(BookingWizard::class, ['business' => $business])
            ->call('chooseService', $foreignService->id)->assertSet('serviceId', null)
            ->call('chooseService', $service->id)
            ->call('chooseStaff', (string) $foreignStaff->id)->assertSet('step', 3)
            ->call('chooseDate', '2020-01-01')->assertSet('date', null);
    }

    public function test_tampered_slot_is_rechecked_on_booking(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();

        Livewire::test(BookingWizard::class, ['business' => $business])
            ->call('chooseService', $service->id)->call('chooseStaff', 'any')
            ->set('selectedSlot', '2026-11-02T03:00:00Z') // 03:00 is outside working hours
            ->set('name', 'X')->set('email', 'x@example.com')
            ->call('book')
            ->assertHasErrors('booking');

        $this->assertDatabaseCount('abs_appointments', 0);
    }

    public function test_manage_page_needs_a_valid_signature(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = Appointment::factory()->create(['staff_id' => $staff->id, 'business_id' => $business->id]);
        $appointment->items()->create(['service_name' => 'S', 'duration_minutes' => 30, 'unit_price_minor' => 0, 'line_total_minor' => 0]);

        $this->get(route('abs.public.manage', $appointment->booking_reference))->assertForbidden();
        $this->get($appointment->manageUrl().'x')->assertForbidden();
        $this->get($appointment->manageUrl())->assertOk()->assertSee($appointment->booking_reference);
    }

    public function test_guest_cancels_through_signed_link_within_policy(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['cancellation_notice_minutes' => 60]);
        $appointment = Appointment::factory()->at($this->utc('2026-11-05 10:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id]);

        $this->post(route('abs.public.cancel', $appointment->booking_reference))->assertForbidden();
        $this->post(URL::signedRoute('abs.public.cancel', ['reference' => $appointment->booking_reference]))->assertRedirect();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame('customer', $appointment->statusHistories()->reorder('id', 'desc')->first()->actor_type->value);
    }

    public function test_customer_reschedules_from_manage_page(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60, 'reschedule_notice_minutes' => 60]);
        $appointment = Appointment::factory()->at($this->utc('2026-11-05 10:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'service_id' => $service->id]);
        $appointment->items()->create(['service_id' => $service->id, 'service_name' => 'S', 'duration_minutes' => 30, 'unit_price_minor' => 5000, 'line_total_minor' => 5000]);

        Livewire::test(RescheduleForm::class, ['appointment' => $appointment, 'mode' => 'customer'])
            ->set('date', '2026-11-06')
            ->set('selectedSlot', '2026-11-06T13:00:00Z')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-11-06 13:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_mode_of_reschedule_form_is_locked(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = Appointment::factory()->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'service_id' => $service->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(RescheduleForm::class, ['appointment' => $appointment, 'mode' => 'customer'])->set('mode', 'staff');
    }

    public function test_service_filter_search_and_summary(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness(['slot_interval_minutes' => 60]);
        $hair = $business->serviceCategories()->create(['name' => 'Hair']);
        $dental = $business->serviceCategories()->create(['name' => 'Dental']);
        $service->update(['name' => 'Haircut', 'category_id' => $hair->id]);
        $tooth = Service::factory()->create(['business_id' => $business->id, 'name' => 'Tooth whitening', 'category_id' => $dental->id]);
        $staff->services()->attach($tooth->id);

        $wizard = Livewire::test(BookingWizard::class, ['business' => $business])
            ->assertSee('Haircut')->assertSee('Tooth whitening')
            ->call('chooseCategory', (string) $dental->id)
            ->assertDontSee('Haircut')->assertSee('Tooth whitening')
            ->call('chooseCategory', '999999')->assertSet('category', '')
            ->set('search', 'hair')
            ->assertSee('Haircut')->assertDontSee('Tooth whitening')
            ->call('chooseService', $service->id)
            ->call('chooseStaff', (string) $staff->id)
            ->call('chooseDate', '2026-11-02')
            ->call('chooseSlot', '2026-11-02T09:00:00Z');

        $summary = $wizard->instance()->summary;
        $this->assertSame('Haircut', $summary['service']);
        $this->assertSame($staff->name, $summary['staff']);
        $this->assertStringContainsString('09:00 AM', $summary['when']);
        $this->assertSame('USD 50.00', $summary['price']);

        // Jumping back is allowed, jumping forward is not.
        $wizard->call('goToStep', 3)->assertSet('step', 3)->call('goToStep', 6)->assertSet('step', 3);
    }

    public function test_booking_rate_limit(): void
    {
        config(['abs.rate_limits.booking' => 2]);
        [$business, $service] = $this->bookableBusiness();
        $wizard = Livewire::test(BookingWizard::class, ['business' => $business])->set('name', 'X')->set('email', 'x@example.com');

        $wizard->call('book')->call('book')->call('book')->assertHasErrors('booking');
        $this->assertStringContainsString('Too many', $wizard->errors()->first('booking'));
    }
}
