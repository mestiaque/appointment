<?php

namespace ME\Abs\Tests\Unit;

use InvalidArgumentException;
use ME\Abs\Support\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    public function test_exponent_depends_on_currency(): void
    {
        $this->assertSame(2, Currency::exponent('USD'));
        $this->assertSame(0, Currency::exponent('jpy'));
        $this->assertSame(3, Currency::exponent('KWD'));
    }

    public function test_decimal_strings_convert_to_minor_units_without_floats(): void
    {
        $this->assertSame(1250, Currency::toMinor('12.5', 'USD'));
        $this->assertSame(120000, Currency::toMinor('1,200.00', 'BDT'));
        $this->assertSame(1500, Currency::toMinor('1500', 'JPY'));
        $this->assertSame(1234, Currency::toMinor('1.234', 'KWD'));
        $this->assertSame(29, Currency::toMinor('0.29', 'USD'));
        $this->assertSame(0, Currency::toMinor('0', 'USD'));
    }

    public function test_too_many_decimals_or_garbage_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Currency::toMinor('10.555', 'USD');
    }

    public function test_negative_or_non_numeric_amounts_are_rejected(): void
    {
        foreach (['-5', '1e3', 'abc', ''] as $bad) {
            try {
                Currency::toMinor($bad, 'USD');
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_formatting_respects_exponent(): void
    {
        $this->assertSame('USD 1,250.00', Currency::format(125000, 'USD'));
        $this->assertSame('JPY 1,500', Currency::format(1500, 'JPY'));
        $this->assertSame('KWD 1.234', Currency::format(1234, 'KWD'));
        $this->assertSame('USD 0.05', Currency::format(5, 'USD'));
        $this->assertSame('0.05', Currency::toDecimal(5, 'USD'));
    }

    public function test_percentages_round_half_up(): void
    {
        $this->assertSame(750, Currency::percentOf(5000, 1500));   // 15 %
        $this->assertSame(1, Currency::percentOf(5, 1500));       // 0.75 -> 1
        $this->assertSame(0, Currency::percentOf(3, 1500));       // 0.45 -> 0
        $this->assertSame(0, Currency::percentOf(5000, 0));
    }
}
