<?php

namespace ME\Abs\Exceptions;

use RuntimeException;

/**
 * A booking rule was not met. The message is safe to show to the customer.
 */
class BookingException extends RuntimeException
{
    public static function slotUnavailable(): self
    {
        return new self(__('abs::abs.slot_unavailable'));
    }
}
