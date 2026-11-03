<?php

namespace ME\Abs\Services\Booking;

use ME\Abs\Enums\DepositType;
use ME\Abs\Models\BookingSetting;
use ME\Abs\Support\Currency;

/**
 * Server-side price calculation. Tax: tax_rate_bps of the discounted subtotal, added on top (0 = no tax).
 * Deposit: fixed amount (capped at total) or whole-number percent of the total, rounded half up.
 */
class PriceCalculator
{
    public function quote(ServiceOffer $offer, BookingSetting $settings, int $quantity = 1, int $discountMinor = 0): PriceQuote
    {
        $quantity = max(1, $quantity);
        $subtotal = $offer->unitPriceMinor * $quantity;
        $discount = min(max(0, $discountMinor), $subtotal);
        $taxable = $subtotal - $discount;
        $tax = Currency::percentOf($taxable, $settings->tax_rate_bps);
        $total = $taxable + $tax;

        $service = $offer->service;
        $deposit = match ($service->deposit_type) {
            DepositType::Fixed => min($service->deposit_value, $total),
            DepositType::Percent => Currency::percentOf($total, min(100, $service->deposit_value) * 100),
            default => 0,
        };

        return new PriceQuote($offer->unitPriceMinor, $quantity, $subtotal, $discount, $tax, $total, $deposit);
    }
}
