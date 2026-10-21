<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;

final class ItemRepository
{
    /**
     * @var array<int, Item>
     */
    private array $items = [];

    /**
     * @return list<Item>
     */
    public function findAll(): array
    {
        return array_values($this->items);
    }

    public function find(int $id): ?Item
    {
        return $this->items[$id] ?? null;
    }

    public function save(Item $item): void
    {
        $item->id ??= \count($this->items) + 1;
        $this->items[$item->id] = $item;
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }
}
