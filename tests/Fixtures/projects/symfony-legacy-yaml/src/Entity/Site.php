<?php

declare(strict_types=1);

namespace App\Entity;

final class Site
{
    public function __construct(public string $name, public string $country = 'LU')
    {
    }
}
