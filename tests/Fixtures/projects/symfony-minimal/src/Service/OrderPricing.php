<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Repository\ProductRepository;

final readonly class OrderPricing
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function price(Order $order): void
    {
        $order->total = $this->products->byName($order->product)->price;
    }
}
