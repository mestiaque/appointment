<?php

namespace ME\Abs\Payments;

final readonly class CheckoutSession
{
    public function __construct(public string $reference, public string $url) {}
}
