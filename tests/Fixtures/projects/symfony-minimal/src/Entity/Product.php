<?php

declare(strict_types=1);

namespace App\Entity;

use App\ValueObject\Money;

final class Product
{
    public Money $price;

    public function __construct(public string $name)
    {
        $this->price = new Money(0, 'EUR');
    }
}
