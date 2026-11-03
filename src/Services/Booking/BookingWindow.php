<?php

namespace ME\Abs\Services\Booking;

use Carbon\CarbonImmutable;
use ME\Abs\Models\BookingSetting;

/**
 * Earliest and latest start a customer may book, from the business's notice and horizon settings.
 */
final readonly class BookingWindow
{
    public function __construct(public CarbonImmutable $earliest, public CarbonImmutable $latest) {}

    public static function for(BookingSetting $settings, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return new self(
            $now->addMinutes($settings->min_notice_minutes),
            $now->addDays($settings->max_days_ahead),
        );
    }

    public function allows(CarbonImmutable $start): bool
    {
        return $start->greaterThanOrEqualTo($this->earliest) && $start->lessThanOrEqualTo($this->latest);
    }
}
