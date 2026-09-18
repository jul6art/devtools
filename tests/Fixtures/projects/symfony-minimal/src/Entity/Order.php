<?php

declare(strict_types=1);

namespace App\Entity;

use App\ValueObject\Money;

final class Order
{
    public ?int $id = null;

    public string $customer = '';

    public string $product = '';

    public ?Money $total = null;

    public string $status = 'draft';

    public string $country = 'LU';

    public string $currency = 'EUR';
}
