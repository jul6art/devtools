<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;

/**
 * The tier is decided one call away from where it is written (ADR-0044): `tierOf()` holds the
 * conditions, and `defaultTier()`, a second call away, is not followed.
 */
final readonly class CustomerTiering
{
    public function apply(Customer $customer): void
    {
        $customer->tier = $this->tierOf($customer);
    }

    private function tierOf(Customer $customer): string
    {
        if ($customer->creditLimit > 100000) {
            return 'platinum';
        }

        if ($customer->creditLimit > 10000) {
            return 'gold';
        }

        return $this->defaultTier($customer);
    }

    private function defaultTier(Customer $customer): string
    {
        return 'CH' === $customer->country ? 'standard-ch' : 'standard';
    }
}
