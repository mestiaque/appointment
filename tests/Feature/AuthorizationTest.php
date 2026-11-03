<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Livewire\AppointmentTable;
use ME\Abs\Livewire\ManualBooking;
use ME\Abs\Livewire\RescheduleForm;
use ME\Abs\Livewire\ScheduleEditor;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Staff;
use ME\Abs\Support\CurrentBusiness;
use ME\Abs\Tests\TestCase;

/**
 * Horizontal privilege escalation, IDOR and permission enforcement.
 */
class AuthorizationTest extends TestCase
{
    private function twoBusinesses(): array
    {
        [$mine, $myService, $myStaff] = $this->bookableBusiness();
        [$theirs, $theirService, $theirStaff] = $this->bookableBusiness();
        $owner = $this->member($mine);
        $theirAppointment = Appointment::factory()->create(['staff_id' => $theirStaff->id, 'business_id' => $theirs->id]);

        return compact('mine', 'myService', 'myStaff', 'theirs', 'theirService', 'theirStaff', 'owner', 'theirAppointment');
    }

    public function test_records_of_another_business_return_404_even_by_id(): void
    {
        extract($this->twoBusinesses());
        $theirBranch = $theirs->branches()->create(['name' => 'Theirs']);
        $theirCategory = $theirs->serviceCategories()->create(['name' => 'Theirs']);
        $theirHoliday = $theirs->holidays()->create(['date' => '2026-12-25', 'name' => 'X']);
        $theirPayment = Payment::query()->create(['business_id' => $theirs->id, 'appointment_id' => $theirAppointment->id, 'provider' => 'cash', 'purpose' => 'full', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded']);

        $this->actingInBusiness($owner, $mine);

        foreach ([
            ['get', route('abs.services.edit', $theirService->id)],
            ['put', route('abs.services.update', $theirService->id)],
            ['delete', route('abs.services.destroy', $theirService->id)],
            ['get', route('abs.staff.edit', $theirStaff->id)],
            ['get', route('abs.staff.schedule', $theirStaff->id)],
            ['delete', route('abs.staff.destroy', $theirStaff->id)],
            ['get', route('abs.branches.edit', $theirBranch->id)],
            ['delete', route('abs.categories.destroy', $theirCategory->id)],
            ['delete', route('abs.holidays.destroy', $theirHoliday->id)],
            ['get', route('abs.appointments.show', $theirAppointment->id)],
            ['post', route('abs.appointments.transition', $theirAppointment->id), ['status' => 'cancelled']],
            ['get', route('abs.appointments.invoice', $theirAppointment->id)],
            ['post', route('abs.payments.store', $theirAppointment->id), ['amount' => '1', 'method' => 'cash']],
            ['post', route('abs.payments.refund', $theirPayment->id), ['amount' => '1']],
        ] as $request) {
            [$method, $url, $data] = array_pad($request, 3, []);
            $this->assertSame(404, $this->{$method}($url, $data)->status(), "{$method} {$url}");
        }

        $this->assertDatabaseHas('abs_services', ['id' => $theirService->id, 'deleted_at' => null]);
        $this->assertSame('confirmed', $theirAppointment->fresh()->status->value);
        $this->assertDatabaseCount('abs_refunds', 0);
    }

    public function test_tampered_business_id_in_session_falls_back_to_an_allowed_business(): void
    {
        extract($this->twoBusinesses());
        $myService->update(['name' => 'My own service']);
        $theirService->update(['name' => 'Competitor secret service']);

        $this->actingAs($owner)->withSession([CurrentBusiness::SESSION_KEY => $theirs->id])
            ->get(route('abs.services.index'))
            ->assertOk()
            ->assertDontSee(e($theirService->name))
            ->assertSee(e($myService->name));
    }

    public function test_cannot_switch_into_a_business_without_membership(): void
    {
        extract($this->twoBusinesses());

        $this->actingAs($owner)->post(route('abs.businesses.switch', $theirs->id))->assertForbidden();
    }

    public function test_foreign_ids_in_form_payloads_are_rejected(): void
    {
        extract($this->twoBusinesses());
        $theirCategory = $theirs->serviceCategories()->create(['name' => 'Theirs']);
        $theirBranch = $theirs->branches()->create(['name' => 'Theirs']);

        $this->actingInBusiness($owner, $mine)->post(route('abs.services.store'), [
            'name' => 'Sneaky', 'duration_minutes' => 30, 'price' => '10', 'deposit_type' => 'none', 'category_id' => $theirCategory->id,
        ])->assertSessionHasErrors('category_id');

        $this->post(route('abs.staff.store'), [
            'name' => 'Sneaky', 'services' => [['id' => $theirService->id, 'enabled' => 1]], 'branches' => [$theirBranch->id],
        ])->assertSessionHasErrors(['services.0.id', 'branches.0']);

        $this->post(route('abs.holidays.store'), ['date' => '2026-12-25', 'name' => 'X', 'branch_id' => $theirBranch->id])
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseMissing('abs_services', ['name' => 'Sneaky']);
    }

    public function test_metheme_permission_is_required_even_for_owners(): void
    {
        [$business] = $this->bookableBusiness();
        $user = $this->member($business, BusinessRole::Owner, ['abs_appointment.view']);

        $this->actingInBusiness($user, $business)->get(route('abs.appointments.index'))->assertOk();
        $this->get(route('abs.services.index'))->assertForbidden();
        $this->get(route('abs.reports.export', 'appointments'))->assertForbidden();
    }

    public function test_membership_role_limits_permissions(): void
    {
        [$business] = $this->bookableBusiness();
        $receptionist = $this->member($business, BusinessRole::Receptionist);

        $this->actingInBusiness($receptionist, $business)->get(route('abs.appointments.index'))->assertOk();
        $this->get(route('abs.settings.edit'))->assertForbidden();
        $this->get(route('abs.reports.export', 'appointments'))->assertForbidden();
        $this->delete(route('abs.businesses.destroy'))->assertForbidden();
    }

    public function test_staff_role_sees_only_own_appointments(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $other = Staff::factory()->workingHours()->offering($service)->create(['business_id' => $business->id]);
        $user = $this->member($business, BusinessRole::Staff);
        $staff->update(['user_id' => $user->id]);

        $own = Appointment::factory()->at(CarbonImmutable::now()->addMinutes(30))->create(['staff_id' => $staff->id, 'business_id' => $business->id]);
        $notOwn = Appointment::factory()->create(['staff_id' => $other->id, 'business_id' => $business->id]);

        $this->actingInBusiness($user, $business);
        $this->get(route('abs.appointments.show', $own->id))->assertOk();
        $this->get(route('abs.appointments.show', $notOwn->id))->assertNotFound();
        $this->post(route('abs.appointments.transition', $own->id), ['status' => 'cancelled'])->assertForbidden();
        $this->post(route('abs.appointments.transition', $own->id), ['status' => 'checked_in'])->assertRedirect();
        $this->assertSame('checked_in', $own->fresh()->status->value);

        Livewire::test(AppointmentTable::class)
            ->assertSee($own->booking_reference)
            ->assertDontSee($notOwn->booking_reference);
    }

    public function test_inactive_membership_loses_access(): void
    {
        [$business] = $this->bookableBusiness();
        $user = $this->member($business, BusinessRole::Manager);
        $business->members()->where('user_id', $user->id)->update(['is_active' => false]);

        $this->actingInBusiness($user, $business)->get(route('abs.appointments.index'))->assertRedirect(route('abs.businesses.index'));
    }

    public function test_super_admin_has_explicit_access_to_every_business(): void
    {
        [$business, $service] = $this->bookableBusiness();
        $admin = UserFactory::new()->superAdmin()->create();

        $this->actingInBusiness($admin, $business)->get(route('abs.services.index'))->assertOk()->assertSee(e($service->name));
    }

    public function test_unauthorized_livewire_calls_are_refused(): void
    {
        extract($this->twoBusinesses());
        $viewer = $this->member($mine, BusinessRole::Owner, ['abs_appointment.view', 'abs_schedule.view']);
        $this->actingInBusiness($viewer, $mine);

        // Manual booking needs abs_appointment.create.
        Livewire::test(ManualBooking::class)->assertForbidden();

        // Schedule save needs abs_schedule.edit.
        Livewire::test(ScheduleEditor::class, ['staff' => $myStaff])->call('save')->assertForbidden();

        // Staff-mode reschedule of a foreign appointment.
        Livewire::test(RescheduleForm::class, ['appointment' => $theirAppointment, 'mode' => 'staff'])->assertForbidden();
    }

    public function test_locked_livewire_ids_cannot_be_tampered(): void
    {
        [$business, $service, $staff] = $this->bookableBusiness();
        $owner = $this->member($business);
        [, , $foreignStaff] = $this->bookableBusiness();
        $this->actingInBusiness($owner, $business);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ScheduleEditor::class, ['staff' => $staff])->set('staffId', $foreignStaff->id);
    }

    public function test_logo_upload_rejects_non_images(): void
    {
        [$business] = $this->bookableBusiness();
        $owner = $this->member($business);

        $this->actingInBusiness($owner, $business)->put(route('abs.businesses.update'), [
            'name' => 'Ok', 'timezone' => 'UTC', 'currency' => 'USD', 'status' => 'active',
            'logo' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('logo');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('abs.dashboard'))->assertRedirect(route('login'));
        $this->get(route('abs.reports.export', 'appointments'))->assertRedirect(route('login'));
    }

    public function test_booking_pages_send_security_headers(): void
    {
        [$business] = $this->bookableBusiness();
        $owner = $this->member($business);

        foreach ([route('abs.public.show', $business->slug), route('abs.public.find'), route('abs.account.login')] as $url) {
            $this->get($url)->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'same-origin');
        }
        $this->actingInBusiness($owner, $business)->get(route('abs.dashboard'))->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_last_owner_cannot_be_removed_or_demoted(): void
    {
        [$business] = $this->bookableBusiness();
        $owner = $this->member($business);
        $membership = $business->members()->where('user_id', $owner->id)->first();

        $this->actingInBusiness($owner, $business)->delete(route('abs.members.destroy', $membership->id))->assertStatus(422);
        $this->put(route('abs.members.update', $membership->id), ['role' => 'staff', 'is_active' => 1])->assertStatus(422);
    }

    public function test_manager_cannot_grant_owner_role(): void
    {
        [$business] = $this->bookableBusiness();
        $manager = $this->member($business, BusinessRole::Manager);
        $other = UserFactory::new()->create();

        $this->actingInBusiness($manager, $business)->post(route('abs.members.store'), ['email' => $other->email, 'role' => 'owner'])->assertForbidden();
        $this->post(route('abs.members.store'), ['email' => $other->email, 'role' => 'staff'])->assertRedirect();
        $this->assertDatabaseHas('abs_business_members', ['user_id' => $other->id, 'role' => 'staff']);
    }
}
