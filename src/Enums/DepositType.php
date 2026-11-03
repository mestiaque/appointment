<?php

namespace ME\Abs\Enums;

enum DepositType: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percent = 'percent';
}
