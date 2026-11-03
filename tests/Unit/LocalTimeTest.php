<?php

namespace ME\Abs\Tests\Unit;

use ME\Abs\Support\LocalTime;
use PHPUnit\Framework\TestCase;

class LocalTimeTest extends TestCase
{
    public function test_local_time_converts_to_utc(): void
    {
        $this->assertSame('2026-06-01T03:00:00Z', LocalTime::toUtc('2026-06-01', '09:00', 'Asia/Dhaka')->toIso8601ZuluString());
        $this->assertSame('2026-06-01T13:00:00Z', LocalTime::toUtc('2026-06-01', '09:00', 'America/New_York')->toIso8601ZuluString());
    }

    public function test_nonexistent_time_in_dst_gap_is_rejected(): void
    {
        // 2026-03-08 02:00-03:00 does not exist in New York.
        $this->assertNull(LocalTime::toUtc('2026-03-08', '02:30', 'America/New_York'));
        $this->assertNotNull(LocalTime::toUtc('2026-03-08', '03:00', 'America/New_York'));
    }

    public function test_ambiguous_time_resolves_to_earlier_occurrence(): void
    {
        // 2026-11-01 01:30 happens twice in New York (EDT -4, then EST -5); policy: the earlier one.
        $this->assertSame('2026-11-01T05:30:00Z', LocalTime::toUtc('2026-11-01', '01:30', 'America/New_York')->toIso8601ZuluString());
    }

    public function test_local_day_length_follows_dst(): void
    {
        $this->assertSame(23 * 60, LocalTime::dayInUtc('2026-03-08', 'America/New_York')->minutes());
        $this->assertSame(25 * 60, LocalTime::dayInUtc('2026-11-01', 'America/New_York')->minutes());
        $this->assertSame(24 * 60, LocalTime::dayInUtc('2026-11-01', 'Asia/Dhaka')->minutes());
    }

    public function test_timezone_validation(): void
    {
        $this->assertTrue(LocalTime::isValidTimezone('Asia/Dhaka'));
        $this->assertFalse(LocalTime::isValidTimezone('Mars/Base'));
    }
}
