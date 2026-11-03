<?php

namespace ME\Abs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->randomElement(['Consultation', 'Haircut', 'Check-up', 'Repair visit', 'Photo session']),
            'duration_minutes' => 30,
            'price_minor' => 5000,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'deposit_type' => 'none',
            'deposit_value' => 0,
            'is_active' => true,
        ];
    }
}
