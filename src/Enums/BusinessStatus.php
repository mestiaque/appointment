<?php

namespace ME\Abs\Enums;

enum BusinessStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return $this === self::Active ? 'success' : 'secondary';
    }
}
