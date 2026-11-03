<?php

namespace ME\Abs\Services\Booking;

use Carbon\CarbonImmutable;
use ME\Abs\Models\Business;
use ME\Models\User;

/**
 * Input for AppointmentBookingAction. Ids come from the client and are verified by the action.
 */
final readonly class BookingRequest
{
    public function __construct(
        public Business $business,
        public int $serviceId,
        public ?int $staffId,
        public ?int $branchId,
        public CarbonImmutable $startsAt,
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone = null,
        public ?string $customerNotes = null,
        public ?User $customer = null,
        public bool $payOnline = false,
        public string $source = 'online',
        public ?User $actor = null,
        public ?string $internalNotes = null,
    ) {}

    public function isStaffBooking(): bool
    {
        return $this->source === 'admin';
    }
}
