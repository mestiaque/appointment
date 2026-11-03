<?php

namespace ME\Abs\Payments;

use ME\Abs\Enums\RefundStatus;

final readonly class RefundResult
{
    public function __construct(public RefundStatus $status, public ?string $providerReference = null, public ?string $failureReason = null) {}
}
