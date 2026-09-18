<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Repository\ProductRepository;
use App\ValueObject\Money;

final readonly class OrderPricing
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function price(Order $order): void
    {
        $order->total = $this->products->byName($order->product)->price;

        if ('' === $order->customer) {
            $order->status = 'draft';
        } elseif (null === $order->total) {
            $order->status = 'quoted';
        } else {
            $order->status = 'priced';
        }

        $order->currency = match ($order->country) {
            'CH' => 'CHF',
            'GB' => 'GBP',
            default => 'EUR',
        };
    }

    public function discount(Order $order): Money
    {
        return $order->total ?? new Money(0, 'EUR');
    }
}
