<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Support\CustomerAccount;
use ME\Abs\Tests\TestCase;
use ME\Models\User;

class AccountTest extends TestCase
{
    public function test_customer_registers_gets_customer_role_and_lands_in_the_customer_panel(): void
    {
        $this->post(route('abs.account.register.store'), [
            'name' => 'Nadia Islam', 'email' => 'Nadia@Example.com', 'phone' => '01700000000',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect(route('abs.account.dashboard'));

        $user = User::query()->where('email', 'nadia@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['customer'], $user->roles()->pluck('slug')->all());
        $this->assertTrue(CustomerAccount::isCustomer($user));
        $this->get(route('abs.account.dashboard'))->assertOk()->assertSee('Nadia Islam');
    }

    public function test_customers_are_kept_out_of_the_admin_panel_even_via_metheme_login(): void
    {
        $customer = UserFactory::new()->create(['password' => 'secret-pass-1']);
        $customer->roles()->attach(CustomerAccount::role()->getKey());

        // metheme's own login redirects to the admin home; the guard sends customers to their panel instead.
        $this->post('/login', ['user_name' => $customer->email, 'password' => 'secret-pass-1']);
        $this->assertAuthenticatedAs($customer);

        foreach (['/admin', '/admin/booking', '/admin/booking/appointments', '/admin/users', '/admin/profile'] as $url) {
            $this->get($url)->assertRedirect(route('abs.account.dashboard'));
        }
    }

    public function test_staff_and_admins_keep_admin_access_and_may_use_the_customer_panel(): void
    {
        [$business] = $this->bookableBusiness();
        $owner = $this->member($business);

        $this->assertFalse(CustomerAccount::isCustomer($owner));
        $this->actingInBusiness($owner, $business)->get(route('abs.dashboard'))->assertOk();
        $this->get(route('abs.account.dashboard'))->assertOk();
    }

    public function test_customer_login_is_rate_limited_and_ignores_admin_intended_urls(): void
    {
        $customer = UserFactory::new()->create(['password' => 'secret-pass-1']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('abs.account.login.store'), ['email' => $customer->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post(route('abs.account.login.store'), ['email' => $customer->email, 'password' => 'secret-pass-1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        RateLimiter::clear('abs-account-login:'.strtolower($customer->email).'|127.0.0.1');
        $this->withSession(['url.intended' => url('/admin/users')])
            ->post(route('abs.account.login.store'), ['email' => $customer->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('abs.account.dashboard'));
    }

    public function test_inactive_account_cannot_sign_in(): void
    {
        $user = UserFactory::new()->create(['password' => 'secret-pass-1', 'is_active' => 0]);

        $this->post(route('abs.account.login.store'), ['email' => $user->email, 'password' => 'secret-pass-1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guests_on_customer_pages_go_to_the_customer_login(): void
    {
        $this->get(route('abs.account.dashboard'))->assertRedirect(route('abs.account.login'));
        $this->get('/admin/booking')->assertRedirect(route('login'));
    }

    public function test_my_bookings_lists_only_own_bookings_with_tabs_and_details(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        [$business, $service, $staff] = $this->bookableBusiness();
        $me = UserFactory::new()->create();
        $other = UserFactory::new()->create();
        $make = function ($user, string $at, string $name, string $status = 'confirmed') use ($business, $staff) {
            $a = Appointment::factory()->status(AppointmentStatus::from($status))->at($this->utc($at))
                ->create(['staff_id' => $staff->id, 'business_id' => $business->id, 'customer_user_id' => $user->id]);
            $a->items()->create(['service_name' => $name, 'duration_minutes' => 30, 'unit_price_minor' => 0, 'line_total_minor' => 0]);

            return $a;
        };
        $upcoming = $make($me, '2026-11-05 10:00', 'Upcoming Unique Service');
        $make($me, '2026-10-20 10:00', 'Past Unique Service', 'completed');
        $make($me, '2026-11-06 10:00', 'Cancelled Unique Service', 'cancelled');
        $theirs = $make($other, '2026-11-06 10:00', 'Someone Else Service');

        $this->actingAs($me);
        $this->get(route('abs.account.dashboard'))->assertOk()
            ->assertSeeInOrder(['Upcoming Unique Service', 'Past Unique Service'])
            ->assertDontSee('Someone Else Service');
        $this->get(route('abs.account.dashboard', ['tab' => 'upcoming']))->assertSee('Upcoming Unique Service')->assertDontSee('Past Unique Service')->assertDontSee('Cancelled Unique Service');
        $this->get(route('abs.account.dashboard', ['tab' => 'past']))->assertSee('Past Unique Service')->assertDontSee('Upcoming Unique Service');
        $this->get(route('abs.account.dashboard', ['tab' => 'cancelled']))->assertSee('Cancelled Unique Service')->assertDontSee('Past Unique Service');

        // Details: own booking opens, someone else's does not.
        $details = $this->get(route('abs.account.booking', $upcoming->booking_reference));
        $details->assertRedirect();
        $this->get($details->headers->get('Location'))->assertOk()->assertSee($upcoming->booking_reference)->assertSee('My bookings');
        $this->get(route('abs.account.booking', $theirs->booking_reference))->assertNotFound();
    }

    public function test_profile_and_password_update(): void
    {
        $user = UserFactory::new()->create(['password' => 'secret-pass-1']);
        $this->actingAs($user);

        $this->put(route('abs.account.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com', 'phone' => '01800000000'])->assertSessionHasNoErrors();
        $this->assertSame('New Name', $user->fresh()->name);

        $this->put(route('abs.account.password.update'), ['current_password' => 'wrong', 'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2'])
            ->assertSessionHasErrors('current_password');
        $this->put(route('abs.account.password.update'), ['current_password' => 'secret-pass-1', 'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('another-pass-2', $user->fresh()->password));
    }

    public function test_logout(): void
    {
        $this->actingAs(UserFactory::new()->create())->post(route('abs.account.logout'))->assertRedirect(route('abs.account.login'));
        $this->assertGuest();
    }
}
