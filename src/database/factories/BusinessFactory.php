<?php

namespace ME\Abs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Models\Business;
use ME\Abs\Models\BusinessMember;
use ME\Models\User;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'email' => fake()->companyEmail(),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'status' => 'active',
        ];
    }

    public function withMember(User $user, BusinessRole $role = BusinessRole::Owner): static
    {
        return $this->afterCreating(fn (Business $business) => BusinessMember::query()->create([
            'business_id' => $business->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'is_active' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function withSettings(array $settings): static
    {
        return $this->afterCreating(fn (Business $business) => $business->bookingSetting()->create($settings));
    }
}
