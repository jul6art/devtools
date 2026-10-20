<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validation constraints decide which values a property may hold (ADR-0044): the fixture carries one of
 * each kind DevTools reads.
 */
final class Customer
{
    public ?int $id = null;

    public string $name = '';

    public string $country = 'LU';

    public string $accountManager = '';

    #[Assert\Choice(['standard', 'gold', 'platinum'])]
    public string $tier = 'standard';

    #[Assert\Expression('this.creditLimit >= 0 or this.tier == "platinum"')]
    public int $creditLimit = 0;

    #[Assert\When(expression: 'this.country == "CH"', constraints: [new Assert\NotBlank()])]
    public string $vatNumber = '';

    public ?Site $site = null;
}
