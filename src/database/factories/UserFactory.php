<?php

namespace ME\Abs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ME\Models\Role;
use ME\Models\RolePermission;
use ME\Models\User;

/**
 * Factory for metheme's auth model (ME\Models\User), used by the booking tests and demo data.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'is_active' => 1,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * User whose metheme role grants exactly these permissions.
     *
     * @param  array<int, string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(function (User $user) use ($permissions) {
            $role = Role::query()->create(['name' => 'Role '.Str::random(6), 'slug' => 'role-'.Str::lower(Str::random(10))]);
            RolePermission::query()->create(['role_id' => $role->getKey(), 'permissions' => $permissions]);
            $user->roles()->attach($role);
        });
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
            $user->roles()->syncWithoutDetaching([$role->getKey()]);
        });
    }
}
