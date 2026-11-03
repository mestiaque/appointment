<?php

namespace ME\Abs\Services\Booking;

use Carbon\CarbonImmutable;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;
use ME\Abs\Support\Interval;

/**
 * A service as delivered by one staff member: staff overrides for price and duration already applied.
 */
final readonly class ServiceOffer
{
    public function __construct(
        public Service $service,
        public Staff $staff,
        public int $durationMinutes,
        public int $unitPriceMinor,
    ) {}

    public function serviceInterval(CarbonImmutable $start): Interval
    {
        return new Interval($start, $start->addMinutes($this->durationMinutes));
    }

    /**
     * Service time plus buffers: what the staff member is unavailable for.
     */
    public function blockedInterval(CarbonImmutable $start): Interval
    {
        return new Interval(
            $start->subMinutes($this->service->buffer_before_minutes),
            $start->addMinutes($this->durationMinutes + $this->service->buffer_after_minutes),
        );
    }
}
