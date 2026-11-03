<?php

namespace ME\Abs\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Database\Seeders\AbsRolesSeeder;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;
use ME\Abs\Support\CurrentBusiness;
use ME\Models\User;
use Tests\TestCase as AppTestCase;

abstract class TestCase extends AppTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $attributes
     * @return array{0: Business, 1: Service, 2: Staff}
     */
    protected function bookableBusiness(array $settings = [], array $attributes = [], string $from = '09:00', string $to = '17:00'): array
    {
        $business = Business::factory()->withSettings($settings + ['min_notice_minutes' => 0, 'max_days_ahead' => 365])->create($attributes);
        $service = Service::factory()->create(['business_id' => $business->id]);
        $staff = Staff::factory()->workingHours($from, $to)->offering($service)->create(['business_id' => $business->id]);

        return [$business->fresh(), $service, $staff];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function member(Business $business, BusinessRole $role = BusinessRole::Owner, ?array $permissions = null): User
    {
        $permissions ??= AbsRolesSeeder::roles()['booking-owner']['permissions'];
        $user = UserFactory::new()->withPermissions($permissions)->create();
        $business->members()->create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    protected function actingInBusiness(User $user, Business $business): static
    {
        $this->actingAs($user);
        $this->withSession([CurrentBusiness::SESSION_KEY => $business->id]);

        return $this;
    }

    protected function utc(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'UTC');
    }
}
