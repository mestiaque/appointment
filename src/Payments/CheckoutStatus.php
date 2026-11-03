<?php

namespace ME\Abs\Payments;

final readonly class CheckoutStatus
{
    public const PAID = 'paid';

    public const OPEN = 'open';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public function __construct(
        public string $state,
        public int $amountMinor,
        public string $currency,
        public ?string $providerPaymentId = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }
}
