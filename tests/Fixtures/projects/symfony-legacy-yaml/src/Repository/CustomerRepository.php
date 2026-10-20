<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;

final class CustomerRepository
{
    /** @var array<int, Customer> */
    private array $customers = [];

    public function get(int $id): Customer
    {
        return $this->customers[$id] ??= new Customer();
    }

    public function save(Customer $customer): void
    {
        $customer->id ??= \count($this->customers) + 1;
        $this->customers[$customer->id] = $customer;
    }
}
