<?php

namespace ME\Abs\Services\Booking;

use ME\Abs\Support\Interval;

final readonly class AvailabilityContext
{
    /**
     * @param  array<int, Interval>  $work  working intervals
     * @param  array<int, Interval>  $unavailable  breaks, time off, holidays
     * @param  array<int, Interval>  $busy  blocked time of blocking appointments
     */
    public function __construct(public array $work, public array $unavailable, public array $busy) {}
}
