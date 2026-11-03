<?php

namespace ME\Abs\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;
use Tests\TestCase;

/**
 * Real concurrency against MySQL: separate PHP processes, separate connections, committed data
 * (DatabaseMigrations instead of the RefreshDatabase transaction). Skipped on other drivers,
 * because SQLite cannot reproduce row locking.
 */
class ConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrency test requires MySQL/MariaDB.');
        }
    }

    /**
     * Runs one child process per start time, all released at the same moment.
     *
     * @param  array<int, string>  $starts
     * @return array<int, string> child outputs
     */
    private function race(Business $business, Service $service, string $staff, array $starts): array
    {
        $goAt = (string) (microtime(true) + 3);
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'),
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ]);

        $children = [];
        foreach ($starts as $start) {
            $command = [PHP_BINARY, __DIR__.'/../Concurrency/book.php', base_path(), (string) $business->id, (string) $service->id, $staff, $start, $goAt, '150'];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $children[] = [$process, $pipes];
        }

        $results = [];
        foreach ($children as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]).' '.stream_get_contents($pipes[2]));
            proc_close($process);
        }

        return $results;
    }

    public function test_parallel_requests_for_one_staff_slot_produce_exactly_one_booking(): void
    {
        $business = Business::factory()->withSettings(['min_notice_minutes' => 0, 'max_days_ahead' => 365])->create();
        $service = Service::factory()->create(['business_id' => $business->id]);
        $staff = Staff::factory()->workingHours()->offering($service)->create(['business_id' => $business->id]);

        $results = $this->race($business, $service, (string) $staff->id, array_fill(0, 10, '2027-01-12 10:00'));

        $successes = array_filter($results, fn ($r) => str_starts_with($r, 'OK'));
        $this->assertCount(1, $successes, implode("\n", $results));
        $this->assertSame(1, DB::table('abs_appointments')->where('staff_id', $staff->id)->count());
        foreach (array_diff_key($results, $successes) as $failure) {
            $this->assertStringContainsString('BookingException', $failure);
        }
    }

    public function test_parallel_any_staff_requests_fill_each_staff_member_once(): void
    {
        $business = Business::factory()->withSettings(['min_notice_minutes' => 0, 'max_days_ahead' => 365])->create();
        $service = Service::factory()->create(['business_id' => $business->id]);
        $staffIds = Staff::factory()->count(3)->workingHours()->offering($service)->create(['business_id' => $business->id])->pluck('id');

        $results = $this->race($business, $service, 'any', array_fill(0, 8, '2027-01-12 10:00'));

        $successes = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'OK')));
        $this->assertCount(3, $successes, implode("\n", $results));
        $this->assertEqualsCanonicalizing($staffIds->map(fn ($id) => "OK {$id}")->all(), $successes);
    }

    public function test_overlapping_but_different_start_times_also_conflict(): void
    {
        $business = Business::factory()->withSettings(['min_notice_minutes' => 0, 'max_days_ahead' => 365])->create();
        $service = Service::factory()->create(['business_id' => $business->id, 'duration_minutes' => 60]);
        $staff = Staff::factory()->workingHours()->offering($service)->create(['business_id' => $business->id]);

        $results = $this->race($business, $service, (string) $staff->id, ['2027-01-12 10:00', '2027-01-12 10:30', '2027-01-12 10:15', '2027-01-12 10:45']);

        $rows = DB::table('abs_appointments')->where('staff_id', $staff->id)->orderBy('starts_at')->get(['starts_at', 'ends_at']);
        $this->assertGreaterThanOrEqual(1, $rows->count(), implode("\n", $results));
        for ($i = 1; $i < $rows->count(); $i++) {
            $this->assertGreaterThanOrEqual($rows[$i - 1]->ends_at, $rows[$i]->starts_at, 'Two committed bookings overlap.');
        }
    }
}
