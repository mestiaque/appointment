<?php

namespace ME\Abs\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Business;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Models\Role;
use ME\Models\User;

/**
 * Demo data for screenshots and evaluation. Not for production databases.
 * Creates one generic business (with branches, services, staff, hours) and a demo owner account
 * whose random password is printed once.
 */
class AbsDemoSeeder extends Seeder
{
    public function run(AppointmentBookingAction $booking): void
    {
        $this->call(AbsRolesSeeder::class);

        $password = Str::password(14, symbols: false);
        $owner = User::query()->firstOrCreate(['email' => 'demo.owner@example.com'], [
            'name' => 'Demo Owner', 'password' => $password, 'is_active' => 1,
        ]);
        $owner->roles()->syncWithoutDetaching([Role::query()->where('slug', 'booking-owner')->value('id')]);

        $business = Business::query()->create([
            'name' => 'Northside Studio', 'slug' => 'northside-studio-'.Str::lower(Str::random(4)), 'owner_user_id' => $owner->id,
            'description' => 'A demo business: consultations, styling and repairs — change everything from the admin panel.',
            'email' => 'bookings@example.com', 'phone' => '+1 555 0100', 'address' => '12 Demo Street',
            'timezone' => config('abs.default_timezone', 'UTC'), 'currency' => config('abs.default_currency', 'USD'), 'status' => 'active',
        ]);
        $business->members()->create(['user_id' => $owner->id, 'role' => BusinessRole::Owner, 'is_active' => true]);
        $business->bookingSetting()->create(['min_notice_minutes' => 60, 'max_days_ahead' => 60, 'slot_interval_minutes' => 30, 'reminder_minutes_before' => 1440]);

        $main = $business->branches()->create(['name' => 'Main branch', 'address' => '12 Demo Street']);
        $business->branches()->create(['name' => 'City branch', 'address' => '48 Sample Avenue']);

        $consult = $business->serviceCategories()->create(['name' => 'Consultations', 'sort_order' => 1]);
        $care = $business->serviceCategories()->create(['name' => 'Care & styling', 'sort_order' => 2]);

        $services = collect([
            ['category_id' => $consult->id, 'name' => 'Initial consultation', 'duration_minutes' => 30, 'price_minor' => 4000],
            ['category_id' => $consult->id, 'name' => 'Follow-up visit', 'duration_minutes' => 20, 'price_minor' => 2500, 'buffer_after_minutes' => 5],
            ['category_id' => $care->id, 'name' => 'Haircut & styling', 'duration_minutes' => 45, 'price_minor' => 3500, 'buffer_after_minutes' => 10],
            ['category_id' => $care->id, 'name' => 'Premium session', 'duration_minutes' => 90, 'price_minor' => 12000, 'deposit_type' => 'percent', 'deposit_value' => 25],
        ])->map(fn ($data) => $business->services()->create($data + ['is_active' => true]));

        $team = [
            ['name' => 'Alex Morgan', 'title' => 'Senior consultant', 'services' => [0, 1], 'branches' => [$main->id]],
            ['name' => 'Sam Lee', 'title' => 'Stylist', 'services' => [2, 3], 'branches' => []],
            ['name' => 'Jordan Patel', 'title' => 'Specialist', 'services' => [0, 2, 3], 'branches' => []],
        ];

        foreach ($team as $member) {
            $staff = $business->staff()->create(['name' => $member['name'], 'title' => $member['title'], 'is_active' => true, 'accepts_bookings' => true]);
            $staff->services()->attach($services->only($member['services'])->pluck('id'));
            $staff->branches()->attach($member['branches']);

            foreach ([1, 2, 3, 4, 5] as $weekday) {
                $staff->schedules()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '13:00']);
                $staff->schedules()->create(['weekday' => $weekday, 'start_time' => '14:00', 'end_time' => '18:00']);
            }
            $staff->schedules()->create(['weekday' => 6, 'start_time' => '10:00', 'end_time' => '14:00']);
        }

        $customers = ['Taylor Brooks', 'Riley Chen', 'Casey Novak', 'Jamie Okafor', 'Morgan Silva', 'Drew Haddad'];
        $day = CarbonImmutable::now($business->timezone)->addDay()->startOfDay();

        foreach ($customers as $i => $name) {
            $localStart = $day->addDays(intdiv($i, 2))->setTime(9 + ($i % 2) * 2, 0);
            try {
                $booking->execute(new BookingRequest(
                    $business, $services[$i % 4]->id, null, null, $localStart->utc(), $name,
                    Str::slug($name, '.').'@example.com', source: 'admin', actor: $owner,
                ));
            } catch (BookingException) {
                // Weekend or holiday in the demo window: skip.
            }
        }

        $this->command?->info('Demo business: /'.config('abs.public_path').'/'.$business->slug);
        $this->command?->warn("Demo owner login: demo.owner@example.com / {$password} (shown once — change or delete this account before going live)");
    }
}
