<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Database\Seeders\AbsRolesSeeder;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Support\CurrentBusiness;
use ME\Abs\Tests\TestCase;

/**
 * Every admin page renders inside the metheme master layout for an owner.
 */
class AdminPagesTest extends TestCase
{
    public function test_owner_can_open_every_admin_page(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business, BusinessRole::Owner, AbsRolesSeeder::roles()['booking-owner']['permissions']);
        $appointment = Appointment::factory()->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'service_id' => $service->id]);
        $appointment->items()->create(['service_id' => $service->id, 'service_name' => $service->name, 'duration_minutes' => 30, 'unit_price_minor' => 5000, 'line_total_minor' => 5000]);
        $category = $business->serviceCategories()->create(['name' => 'Hair']);
        $branch = $business->branches()->create(['name' => 'Main']);

        $this->actingInBusiness($owner, $business);

        $pages = [
            route('abs.dashboard') => 'Booking Dashboard',
            route('abs.businesses.index') => $business->name,
            route('abs.businesses.edit') => 'Business Profile',
            route('abs.members.index') => 'Team Members',
            route('abs.settings.edit') => 'Booking Settings',
            route('abs.branches.index') => 'Main',
            route('abs.branches.create') => 'Add Branch',
            route('abs.branches.edit', $branch->id) => 'Edit Branch',
            route('abs.categories.index') => 'Hair',
            route('abs.categories.edit', $category->id) => 'Edit Category',
            route('abs.services.index') => $service->name,
            route('abs.services.create') => 'Add Service',
            route('abs.services.edit', $service->id) => 'Edit Service',
            route('abs.staff.index') => $staff->name,
            route('abs.staff.create') => 'Add Staff',
            route('abs.staff.edit', $staff->id) => 'Edit Staff',
            route('abs.staff.schedule', $staff->id) => 'Weekly working hours',
            route('abs.holidays.index') => 'Holidays',
            route('abs.appointments.index') => $appointment->booking_reference,
            route('abs.appointments.create') => 'Create booking',
            route('abs.appointments.show', $appointment->id) => $appointment->booking_reference,
            route('abs.appointments.invoice', $appointment->id) => 'INV-000001',
            route('abs.calendar') => 'abs-calendar',
            route('abs.payments.index') => 'Payments',
            route('abs.reports.index') => 'Gross booking value',
        ];

        foreach ($pages as $url => $expected) {
            $response = $this->get($url);
            $this->assertSame(200, $response->status(), $url);
            $this->assertStringContainsString(e($expected), $response->getContent(), $url);
        }

        // Admin pages use the existing metheme layout (sidebar includes the booking menu).
        $this->get(route('abs.dashboard'))->assertSee('app-sidebar', false)->assertSee('All Appointments');
    }

    public function test_calendar_feed_returns_events_in_range(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        Appointment::factory()->at($this->utc('2026-11-10 09:00'))->create(['staff_id' => $staff->id, 'business_id' => $business->id]);

        $this->actingInBusiness($owner, $business)
            ->getJson(route('abs.calendar.events', ['start' => '2026-11-09T00:00:00Z', 'end' => '2026-11-16T00:00:00Z']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.start', '2026-11-10T09:00:00+00:00');
    }

    public function test_business_switcher_lists_accessible_businesses_and_switches(): void
    {
        [$first] = $this->bookableBusiness([], ['name' => 'First Clinic']);
        [$second] = $this->bookableBusiness([], ['name' => 'Second Clinic']);
        [$foreign] = $this->bookableBusiness([], ['name' => 'Foreign Clinic']);
        $owner = $this->member($first);
        $second->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);

        $this->actingInBusiness($owner, $first)->get(route('abs.calendar'))
            ->assertOk()
            ->assertSee('aria-label="Current business"', false)
            ->assertSee('Second Clinic')
            ->assertDontSee('Foreign Clinic');

        $this->post(route('abs.businesses.switch', $second->id))->assertRedirect(route('abs.dashboard'));
        $this->get(route('abs.dashboard'))->assertSessionHas(CurrentBusiness::SESSION_KEY, $second->id);
    }

    public function test_user_without_business_is_sent_to_the_business_list(): void
    {
        $user = UserFactory::new()->withPermissions(['abs_appointment.view', 'abs_business.view'])->create();

        $this->actingAs($user)->get(route('abs.dashboard'))->assertRedirect(route('abs.businesses.index'));
    }

    public function test_business_can_be_created_and_creator_becomes_owner(): void
    {
        $user = UserFactory::new()->withPermissions(AbsRolesSeeder::roles()['booking-owner']['permissions'])->create();

        $this->actingAs($user)->post(route('abs.businesses.store'), [
            'name' => 'Smile Dental', 'timezone' => 'Asia/Dhaka', 'currency' => 'BDT', 'status' => 'active',
        ])->assertRedirect(route('abs.settings.edit'));

        $business = Business::query()->where('name', 'Smile Dental')->firstOrFail();
        $this->assertSame('smile-dental', $business->slug);
        $this->assertDatabaseHas('abs_business_members', ['business_id' => $business->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('abs_booking_settings', ['business_id' => $business->id]);
    }

    public function test_invalid_timezone_and_currency_are_rejected(): void
    {
        $user = UserFactory::new()->withPermissions(['abs_business.create'])->create();

        $this->actingAs($user)->post(route('abs.businesses.store'), ['name' => 'X', 'timezone' => 'Mars/Base', 'currency' => 'dollars', 'status' => 'active'])
            ->assertSessionHasErrors(['timezone', 'currency']);
    }
}
