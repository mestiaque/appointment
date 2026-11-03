<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Tests\TestCase;

class DemoSeederTest extends TestCase
{
    public function test_clinic_demo_seeds_a_consistent_clinic_without_sending_mail(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 06:00:00');
        UserFactory::new()->superAdmin()->create();

        $this->artisan('abs:install', ['--clinic-demo' => true])->assertSuccessful();

        $business = Business::query()->where('slug', 'city-care-medical-centre')->firstOrFail();
        $this->assertSame('Asia/Dhaka', $business->timezone);
        $this->assertSame(6, $business->staff()->count());
        $this->assertGreaterThan(30, Appointment::query()->count());
        $this->assertTrue(Appointment::query()->where('status', 'completed')->exists());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('abs_notification_logs')->count());

        // No two active bookings of the same doctor overlap.
        $active = Appointment::query()->whereNotIn('status', ['cancelled', 'rejected', 'expired'])->orderBy('staff_id')->orderBy('blocked_starts_at')->get();
        foreach ($active->groupBy('staff_id') as $rows) {
            for ($i = 1; $i < $rows->count(); $i++) {
                $this->assertTrue($rows[$i]->blocked_starts_at->greaterThanOrEqualTo($rows[$i - 1]->blocked_ends_at));
            }
        }

        // Friday is the weekly off day.
        $this->assertFalse($active->contains(fn ($a) => $a->localStartsAt()->dayOfWeek === 5));
    }
}
