<?php

namespace ME\Abs\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Half-open time interval [start, end) in UTC.
 */
final readonly class Interval
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end)
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Interval end must be after its start.');
        }
    }

    /**
     * Two intervals overlap when a.start < b.end AND a.end > b.start. Touching intervals do not overlap.
     */
    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end) && $this->end->greaterThan($other->start);
    }

    public function contains(self $other): bool
    {
        return $this->start->lessThanOrEqualTo($other->start) && $this->end->greaterThanOrEqualTo($other->end);
    }

    /**
     * Whether $other is fully covered by the union of $intervals.
     *
     * @param  array<int, self>  $intervals
     */
    public static function coveredBy(self $other, array $intervals): bool
    {
        usort($intervals, fn (self $a, self $b) => $a->start <=> $b->start);
        $cursor = $other->start;

        foreach ($intervals as $interval) {
            if ($interval->start->greaterThan($cursor)) {
                return false;
            }
            if ($interval->end->greaterThan($cursor)) {
                $cursor = $interval->end;
            }
            if ($cursor->greaterThanOrEqualTo($other->end)) {
                return true;
            }
        }

        return false;
    }

    public function minutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }
}
