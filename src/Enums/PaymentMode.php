<?php

namespace ME\Abs\Enums;

/**
 * Business-level policy for how customers pay when booking online.
 */
enum PaymentMode: string
{
    case PayLater = 'pay_later';
    case OnlineOptional = 'online_optional';
    case OnlineRequired = 'online_required';
    case DepositRequired = 'deposit_required';

    public function label(): string
    {
        return match ($this) {
            self::PayLater => 'Pay later / cash only',
            self::OnlineOptional => 'Online payment optional',
            self::OnlineRequired => 'Full online payment required',
            self::DepositRequired => 'Online deposit required',
        };
    }

    public function needsOnlineGateway(): bool
    {
        return $this !== self::PayLater;
    }
}
