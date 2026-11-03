<?php

namespace ME\Abs\Enums;

enum ApprovalMode: string
{
    case Instant = 'instant';
    case Approval = 'approval';

    public function label(): string
    {
        return match ($this) {
            self::Instant => 'Instant confirmation',
            self::Approval => 'Owner approval required',
        };
    }
}
