<?php

namespace ME\Abs\Services\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Business;

/**
 * Read-side helper for booking UIs: which dates and times to show. The result is advisory —
 * AppointmentBookingAction re-checks everything under lock when the customer confirms.
 */
class SlotFinder
{
    public function __construct(private BookingEligibilityService $eligibility, private SlotGenerator $generator) {}

    /**
     * @return Collection<int, ServiceOffer>
     */
    public function offers(Business $business, ?int $serviceId, ?int $staffId, ?int $branchId): Collection
    {
        if (! $serviceId) {
            return collect();
        }

        try {
            $branch = $this->eligibility->resolveBranch($business, $branchId);
            $service = $this->eligibility->resolveService($business, $serviceId);
            $offers = $this->eligibility->eligibleOffers($business, $service, $branch);
        } catch (BookingException) {
            return collect();
        }

        return $staffId ? $offers->filter(fn (ServiceOffer $o) => $o->staff->getKey() === $staffId)->values() : $offers;
    }

    /**
     * @return array<int, array{date: string, label: string, available: bool}>
     */
    public function dates(Business $business, Collection $offers, string $fromDate, int $days, ?int $branchId, bool $enforceWindow = true): array
    {
        $window = $enforceWindow ? BookingWindow::for($business->settings()) : null;
        $today = CarbonImmutable::now($business->timezone)->toDateString();
        $available = $offers->isEmpty() ? [] : $this->generator->availableDates($business, $offers, $fromDate, $days, $branchId, $window);

        $dates = [];
        $day = CarbonImmutable::parse($fromDate);
        for ($i = 0; $i < $days; $i++, $day = $day->addDay()) {
            $date = $day->toDateString();
            $dates[] = [
                'date' => $date,
                'label' => $day->format('D, d M'),
                'available' => $date >= $today && in_array($date, $available, true),
            ];
        }

        return $dates;
    }

    /**
     * @return array<int, array{start: string, label: string, staff: array<int, int>}>
     */
    public function times(Business $business, Collection $offers, string $date, ?int $branchId, bool $enforceWindow = true, ?int $ignoreAppointmentId = null): array
    {
        if ($offers->isEmpty()) {
            return [];
        }

        $window = $enforceWindow ? BookingWindow::for($business->settings()) : null;
        $slots = $this->generator->forDate($business, $offers, $date, $branchId, $window, $ignoreAppointmentId);

        $times = [];
        foreach ($slots as $iso => $staffIds) {
            $times[] = [
                'start' => $iso,
                'label' => CarbonImmutable::parse($iso)->setTimezone($business->timezone)->format('h:i A'),
                'staff' => $staffIds,
            ];
        }

        return $times;
    }

    public static function parseSlot(?string $iso): ?CarbonImmutable
    {
        if (! $iso || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $iso)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $iso, 'UTC') ?: null;
    }
}
