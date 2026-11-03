<?php

namespace ME\Abs\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Requested, self::Pending => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
