<?php

namespace ME\Abs\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use ME\Abs\Support\Interval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntervalTest extends TestCase
{
    private function interval(string $start, string $end): Interval
    {
        return new Interval(CarbonImmutable::parse("2026-01-01 {$start}", 'UTC'), CarbonImmutable::parse("2026-01-01 {$end}", 'UTC'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function overlapCases(): array
    {
        return [
            'identical' => ['10:00', '11:00', true],
            'starts inside' => ['10:30', '11:30', true],
            'ends inside' => ['09:30', '10:30', true],
            'contains' => ['09:00', '12:00', true],
            'inside' => ['10:15', '10:45', true],
            'touches end (half-open)' => ['11:00', '12:00', false],
            'touches start (half-open)' => ['09:00', '10:00', false],
            'before' => ['08:00', '09:00', false],
            'after' => ['12:00', '13:00', false],
        ];
    }

    #[DataProvider('overlapCases')]
    public function test_overlap_uses_half_open_intervals(string $start, string $end, bool $expected): void
    {
        $existing = $this->interval('10:00', '11:00');

        $this->assertSame($expected, $existing->overlaps($this->interval($start, $end)));
        $this->assertSame($expected, $this->interval($start, $end)->overlaps($existing));
    }

    public function test_end_must_be_after_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->interval('10:00', '10:00');
    }

    public function test_covered_by_joins_adjacent_intervals(): void
    {
        $target = $this->interval('11:30', '12:30');

        $this->assertTrue(Interval::coveredBy($target, [$this->interval('12:00', '13:00'), $this->interval('09:00', '12:00')]));
        $this->assertFalse(Interval::coveredBy($target, [$this->interval('09:00', '12:00'), $this->interval('12:05', '13:00')]));
        $this->assertFalse(Interval::coveredBy($target, []));
    }
}
