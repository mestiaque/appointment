<?php

namespace ME\Abs\Enums;

enum PaymentPurpose: string
{
    case Full = 'full';
    case Deposit = 'deposit';
    case Balance = 'balance';
}
