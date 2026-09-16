<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;

final class OrderRepository
{
    /** @var array<int, Order> */
    private array $orders = [];

    /** @return list<Order> */
    public function all(): array
    {
        return array_values($this->orders);
    }

    public function get(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function save(Order $order): void
    {
        $order->id ??= \count($this->orders) + 1;
        $this->orders[$order->id] = $order;
    }
}
