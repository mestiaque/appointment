<?php

namespace ME\Abs\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Converts wall-clock times in a business timezone into UTC instants.
 *
 * Daylight-saving policy:
 *  - A local time that does not exist (skipped by a spring-forward gap) returns null and is never bookable.
 *  - A local time that occurs twice (fall-back overlap) resolves to the earlier occurrence.
 */
final class LocalTime
{
    public static function toUtc(string $date, string $time, string $timezone): ?CarbonImmutable
    {
        $time = substr($time, 0, 5);
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", new DateTimeZone($timezone));

        if (! $local || $local->format('Y-m-d H:i') !== "{$date} {$time}") {
            return null;
        }

        // PHP picks the first (earlier, daylight-time) offset for ambiguous times; keep it explicit.
        $earlier = $local->utc();
        $previousHour = $earlier->subHour()->setTimezone($timezone);
        if ($previousHour->format('Y-m-d H:i') === "{$date} {$time}") {
            $earlier = $earlier->subHour();
        }

        return $earlier;
    }

    /**
     * Midnight-to-midnight local day as a UTC interval (23, 24 or 25 hours long).
     */
    public static function dayInUtc(string $date, string $timezone): Interval
    {
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();

        return new Interval($start->utc(), $start->addDay()->startOfDay()->utc());
    }

    public static function today(string $timezone): string
    {
        return CarbonImmutable::now($timezone)->toDateString();
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
