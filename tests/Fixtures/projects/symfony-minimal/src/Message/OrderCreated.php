<?php

declare(strict_types=1);

namespace App\Message;

final readonly class OrderCreated
{
    public function __construct(public int $orderId)
    {
    }
}
