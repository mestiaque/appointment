<?php

namespace ME\Abs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'is_active' => true,
            'accepts_bookings' => true,
        ];
    }

    /**
     * Same working hours (local time) on every weekday in $weekdays.
     *
     * @param  array<int, int>  $weekdays
     */
    public function workingHours(string $start = '09:00', string $end = '17:00', array $weekdays = [0, 1, 2, 3, 4, 5, 6]): static
    {
        return $this->afterCreating(function (Staff $staff) use ($start, $end, $weekdays) {
            foreach ($weekdays as $weekday) {
                $staff->schedules()->create(['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end]);
            }
        });
    }

    public function offering(Service $service, ?int $priceMinor = null, ?int $durationMinutes = null): static
    {
        return $this->afterCreating(fn (Staff $staff) => $staff->services()->attach($service->getKey(), [
            'price_minor' => $priceMinor,
            'duration_minutes' => $durationMinutes,
        ]));
    }
}
