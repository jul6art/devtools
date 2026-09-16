<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Repository\ProductRepository;
use App\Service\OrderPricing;
use PHPUnit\Framework\TestCase;

final class OrderPricingTest extends TestCase
{
    public function testAnOrderIsPricedFromItsProduct(): void
    {
        $order = new Order();
        $order->product = 'Widget XL';

        new OrderPricing(new ProductRepository())->price($order);

        self::assertSame(0, $order->total?->cents);
    }
}
