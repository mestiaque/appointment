<?php

namespace ME\Abs\Services\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\Staff;
use ME\Abs\Support\Interval;
use ME\Abs\Support\LocalTime;

/**
 * Answers "is this staff member free for this interval?".
 *
 * Rules (all must pass):
 *  - the service time [start, end) is fully covered by the staff's working intervals (adjacent intervals,
 *    including ones on consecutive days, are joined, so an appointment may cross midnight);
 *  - it does not overlap a break, a time-off period, or a business holiday (whole local date);
 *  - the blocked time [start - buffer_before, end + buffer_after) does not overlap the blocked time of any
 *    blocking appointment of the same staff.
 *
 * Blocking appointments: confirmed, checked_in, in_progress, completed, no_show; pending when the business
 * setting pending_blocks_slot is on; awaiting_payment until hold_expires_at.
 */
class AvailabilityService
{
    public function isAvailable(
        Business $business,
        Staff $staff,
        Interval $service,
        Interval $blocked,
        ?int $branchId = null,
        ?int $ignoreAppointmentId = null,
        bool $lockAppointments = false,
    ): bool {
        $context = $this->loadContext($business, $staff, $blocked, $branchId, $ignoreAppointmentId, $lockAppointments);

        return $this->fits($context, $service, $blocked);
    }

    /**
     * Everything needed to test many candidate slots in a window with one set of queries.
     */
    public function loadContext(
        Business $business,
        Staff $staff,
        Interval $window,
        ?int $branchId = null,
        ?int $ignoreAppointmentId = null,
        bool $lockAppointments = false,
    ): AvailabilityContext {
        $timezone = $business->timezone;
        $firstDay = $window->start->setTimezone($timezone)->subDay()->startOfDay();
        $lastDay = $window->end->setTimezone($timezone)->addDay()->startOfDay();

        $schedules = $staff->schedules()->get()->groupBy('weekday');
        $breaks = $staff->breaks()->get()->groupBy('weekday');

        $holidayDates = $business->holidays()
            ->whereBetween('date', [$firstDay->toDateString(), $lastDay->toDateString()])
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->pluck('date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
            ->all();

        $work = [];
        $unavailable = [];

        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $date = $day->toDateString();

            if (in_array($date, $holidayDates, true)) {
                $unavailable[] = LocalTime::dayInUtc($date, $timezone);

                continue;
            }

            foreach ($schedules->get($day->dayOfWeek, []) as $row) {
                if ($interval = $this->localInterval($date, $row->start_time, $row->end_time, $timezone)) {
                    $work[] = $interval;
                }
            }

            foreach ($breaks->get($day->dayOfWeek, []) as $row) {
                if ($interval = $this->localInterval($date, $row->start_time, $row->end_time, $timezone)) {
                    $unavailable[] = $interval;
                }
            }
        }

        foreach ($staff->timeOff()->where('starts_at', '<', $lastDay->utc())->where('ends_at', '>', $firstDay->utc())->get() as $off) {
            $unavailable[] = new Interval($off->starts_at->utc(), $off->ends_at->utc());
        }

        $busy = $this->blockingAppointments($business, $staff->getKey(), $firstDay->utc(), $lastDay->utc(), $ignoreAppointmentId, $lockAppointments)
            ->map(fn (Appointment $a) => new Interval($a->blocked_starts_at->utc(), $a->blocked_ends_at->utc()))
            ->all();

        return new AvailabilityContext($work, $unavailable, $busy);
    }

    public function fits(AvailabilityContext $context, Interval $service, Interval $blocked): bool
    {
        if (! Interval::coveredBy($service, $context->work)) {
            return false;
        }

        foreach ($context->unavailable as $interval) {
            if ($interval->overlaps($service)) {
                return false;
            }
        }

        foreach ($context->busy as $interval) {
            if ($interval->overlaps($blocked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Appointments of one staff member that occupy time in the window.
     * With $lock, rows are read with FOR UPDATE, which always sees the latest committed data inside a transaction.
     *
     * @return Collection<int, Appointment>
     */
    public function blockingAppointments(
        Business $business,
        int $staffId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $ignoreAppointmentId = null,
        bool $lock = false,
    ): Collection {
        $pendingBlocks = $business->settings()->pending_blocks_slot;
        $now = CarbonImmutable::now();

        return Appointment::query()
            ->where('staff_id', $staffId)
            ->where('blocked_starts_at', '<', $to)
            ->where('blocked_ends_at', '>', $from)
            ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
            ->where(function ($q) use ($pendingBlocks, $now) {
                $q->whereIn('status', array_map(fn ($s) => $s->value, AppointmentStatus::alwaysBlocking()))
                    ->orWhere(fn ($q) => $q->where('status', AppointmentStatus::AwaitingPayment->value)->where('hold_expires_at', '>', $now));

                if ($pendingBlocks) {
                    $q->orWhere('status', AppointmentStatus::Pending->value);
                }
            })
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['id', 'blocked_starts_at', 'blocked_ends_at']);
    }

    private function localInterval(string $date, string $start, string $end, string $timezone): ?Interval
    {
        $startUtc = LocalTime::toUtc($date, $start, $timezone);
        // "24:00" closes the interval at the next local midnight, so shifts can continue past midnight.
        $endUtc = str_starts_with($end, '24:00')
            ? LocalTime::toUtc(CarbonImmutable::parse($date)->addDay()->toDateString(), '00:00', $timezone)
            : LocalTime::toUtc($date, $end, $timezone);

        // A boundary inside a DST gap (or an interval collapsed by it) is skipped rather than guessed.
        if (! $startUtc || ! $endUtc || $endUtc->lessThanOrEqualTo($startUtc)) {
            return null;
        }

        return new Interval($startUtc, $endUtc);
    }
}
