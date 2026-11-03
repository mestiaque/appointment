<?php

namespace ME\Abs\Services\Booking;

use ME\Abs\Models\Appointment;

/**
 * Random, non-sequential booking references such as "BK-7Q4M9XR2". Crockford alphabet (no I, L, O, U).
 * Uniqueness is enforced by the unique index; this also checks first so a collision is retried, not failed.
 */
class BookingReferenceGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $reference = 'BK-'.$this->randomCode(8);

            if (! Appointment::query()->where('booking_reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new \RuntimeException('Could not generate a unique booking reference.');
    }

    public function randomCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
