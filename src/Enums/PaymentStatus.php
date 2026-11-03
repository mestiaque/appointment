<?php

namespace ME\Abs\Enums;

/**
 * Payment status of an appointment, derived from its payments and refunds.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'secondary',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::PartiallyRefunded, self::Refunded => 'info',
        };
    }
}
