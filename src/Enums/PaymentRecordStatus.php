<?php

namespace ME\Abs\Enums;

/**
 * Status of a single payment record (one charge attempt).
 */
enum PaymentRecordStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Statuses where money was captured (refunds are tracked separately in refunded_minor).
     *
     * @return array<int, self>
     */
    public static function captured(): array
    {
        return [self::Succeeded, self::PartiallyRefunded, self::Refunded];
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::PartiallyRefunded, self::Refunded => 'info',
        };
    }
}
