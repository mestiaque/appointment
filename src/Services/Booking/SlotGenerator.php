<?php

namespace ME\Abs\Services\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use ME\Abs\Models\Business;
use ME\Abs\Support\Interval;
use ME\Abs\Support\LocalTime;

/**
 * Generates bookable start times for one local date. Candidates start at each working interval's start and
 * step by the business slot interval; each one is checked with AvailabilityService rules.
 */
class SlotGenerator
{
    public function __construct(private AvailabilityService $availability) {}

    /**
     * @param  Collection<int, ServiceOffer>  $offers  one per staff member to consider
     * @param  int|null  $ignoreAppointmentId  appointment being rescheduled (its own time does not block it)
     * @return array<string, array<int, int>> UTC ISO start => staff ids free at that start, sorted by time
     */
    public function forDate(Business $business, Collection $offers, string $date, ?int $branchId = null, ?BookingWindow $window = null, ?int $ignoreAppointmentId = null): array
    {
        $settings = $business->settings();
        $step = max(5, $settings->slot_interval_minutes);
        $day = LocalTime::dayInUtc($date, $business->timezone);
        $slots = [];

        foreach ($offers as $offer) {
            $context = $this->availability->loadContext($business, $offer->staff, $day, $branchId, $ignoreAppointmentId);

            foreach ($context->work as $work) {
                if (! $work->overlaps($day)) {
                    continue;
                }

                for ($start = $work->start; $start->lessThan($work->end); $start = $start->addMinutes($step)) {
                    if (! $day->contains(new Interval($start, $start->addMinute()))) {
                        continue;
                    }
                    if ($window && ! $window->allows($start)) {
                        continue;
                    }
                    if ($this->availability->fits($context, $offer->serviceInterval($start), $offer->blockedInterval($start))) {
                        $slots[$start->toIso8601ZuluString()][] = $offer->staff->getKey();
                    }
                }
            }
        }

        ksort($slots);

        return array_map(fn ($ids) => array_values(array_unique($ids)), $slots);
    }

    /**
     * Local dates in [from, from + days) that have at least one slot.
     *
     * @param  Collection<int, ServiceOffer>  $offers
     * @return array<int, string>
     */
    public function availableDates(Business $business, Collection $offers, string $fromDate, int $days, ?int $branchId = null, ?BookingWindow $window = null): array
    {
        $dates = [];
        $day = CarbonImmutable::parse($fromDate);

        for ($i = 0; $i < $days; $i++, $day = $day->addDay()) {
            if ($this->forDate($business, $offers, $day->toDateString(), $branchId, $window) !== []) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }
}
