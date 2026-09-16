<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;

final class ProductRepository
{
    /** @var array<string, Product> */
    private array $products = [];

    public function byName(string $name): Product
    {
        return $this->products[$name] ??= new Product($name);
    }

    public function add(Product $product): void
    {
        $this->products[$product->name] = $product;
    }
}
