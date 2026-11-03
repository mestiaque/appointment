<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Database\Factories\UserFactory;
use ME\Abs\Database\Seeders\AbsClinicDemoSeeder;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Tests\TestCase;

/**
 * N+1 guard: renders the main pages over realistic data (the clinic demo, ~75 bookings) and fails when a page
 * needs more queries than its budget. Budgets leave headroom; a missing eager load blows far past them.
 */
class QueryBudgetTest extends TestCase
{
    /** @var array<string, int> */
    private const BUDGETS = [
        // Admin pages include ~35 queries of metheme's own sidebar/header (User::hasPermission is not cached there).
        '/admin/booking' => 65,
        '/admin/booking/appointments' => 60,
        '/admin/booking/appointments/{appt}' => 75,
        '/admin/booking/calendar' => 55,
        '/admin/booking/calendar/events?start=2026-10-01T00:00:00Z&end=2026-11-15T00:00:00Z' => 12,
        '/admin/booking/payments' => 55,
        '/admin/booking/reports' => 55,
        '/admin/booking/services' => 55,
        '/admin/booking/staff' => 55,
        '/admin/booking/staff/{staff}/schedule' => 55,
        '/admin/booking/payment-gateways' => 55,
        // Customer-facing pages have no metheme chrome.
        '/book/{slug}' => 12,
        '/account' => 15,
    ];

    public function test_pages_stay_within_their_query_budget(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 06:00:00');
        $admin = UserFactory::new()->superAdmin()->create();
        $this->artisan('db:seed', ['--class' => AbsClinicDemoSeeder::class])->assertSuccessful();
        $business = Business::query()->firstOrFail();
        $appointment = Appointment::query()->has('payments')->firstOrFail();
        $customer = UserFactory::new()->create();
        Appointment::query()->limit(10)->update(['customer_user_id' => $customer->id]);

        $report = [];
        foreach (self::BUDGETS as $uri => $budget) {
            $url = strtr($uri, ['{appt}' => $appointment->id, '{staff}' => $business->staff()->value('id'), '{slug}' => $business->slug]);
            $str_customer = str_starts_with($uri, '/account');
            $str_customer ? $this->actingAs($customer) : $this->actingInBusiness($admin, $business);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get($url);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame(200, $response->status(), $url);
            $report[] = sprintf('%4d / %3d  %s', $count, $budget, $url);
            if (getenv('ABS_QUERY_REPORT')) {
                $repeats = collect(DB::getRawQueryLog() ?: [])->countBy(fn ($q) => preg_replace('/\d+/', '?', mb_substr($q['raw_query'] ?? '', 0, 140)))->filter(fn ($n) => $n > 2)->sortDesc()->take(4);
                foreach ($repeats as $sql => $n) {
                    $report[] = "        x{$n} {$sql}";
                }

                continue;
            }
            $this->assertLessThanOrEqual($budget, $count, "{$url} ran {$count} queries (budget {$budget})");
        }

        if (getenv('ABS_QUERY_REPORT')) {
            fwrite(STDERR, "\n".implode("\n", $report)."\n");
        }
    }
}
