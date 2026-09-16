<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderCreated;
use App\Repository\OrderRepository;

final readonly class OrderCreatedHandler
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function __invoke(OrderCreated $message): void
    {
        $this->orders->get($message->orderId);
    }
}
