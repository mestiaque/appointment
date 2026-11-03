<?php

namespace ME\Abs\Services\Booking;

final readonly class PriceQuote
{
    public function __construct(
        public int $unitPriceMinor,
        public int $quantity,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $taxMinor,
        public int $totalMinor,
        public int $depositMinor,
    ) {}
}
