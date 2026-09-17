<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Order;
use App\Repository\ProductRepository;
use App\Service\OrderPricing;

/**
 * A helper of the test suite, not a test: it must never appear as a test of a workflow.
 */
final class OrderFactory
{
    public static function priced(string $product): Order
    {
        $order = new Order();
        $order->product = $product;

        new OrderPricing(new ProductRepository())->price($order);

        return $order;
    }
}
