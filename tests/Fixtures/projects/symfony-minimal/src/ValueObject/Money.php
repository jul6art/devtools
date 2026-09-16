<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class Money
{
    public function __construct(public int $cents, public string $currency)
    {
    }
}
