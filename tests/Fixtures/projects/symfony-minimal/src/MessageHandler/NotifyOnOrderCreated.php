<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderCreated;
use App\Service\OrderPricing;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A second handler of the same message: both belong to one workflow — what happens when an order is created.
 */
#[AsMessageHandler]
final readonly class NotifyOnOrderCreated
{
    public function __construct(private OrderPricing $pricing)
    {
    }

    public function __invoke(OrderCreated $message): void
    {
        unset($message);
    }
}
