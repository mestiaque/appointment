<?php

namespace ME\Abs\Payments;

use ME\Abs\Enums\RefundStatus;

/**
 * Provider-neutral webhook event.
 */
final readonly class GatewayEvent
{
    public const CHECKOUT_COMPLETED = 'checkout.completed';

    public const CHECKOUT_FAILED = 'checkout.failed';

    public const CHECKOUT_EXPIRED = 'checkout.expired';

    public const REFUND_UPDATED = 'refund.updated';

    public const IGNORED = 'ignored';

    public function __construct(
        public string $id,
        public string $providerType,
        public string $kind,
        public ?string $reference = null,
        public ?RefundStatus $refundStatus = null,
        public string $payloadHash = '',
        public ?int $localRefundId = null,
    ) {}
}
