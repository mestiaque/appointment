<?php

namespace ME\Abs\Exceptions;

use ME\Abs\Enums\AppointmentStatus;

class InvalidTransitionException extends BookingException
{
    public static function between(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self("An appointment cannot move from \"{$from->label()}\" to \"{$to->label()}\".");
    }
}
