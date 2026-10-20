<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Site;

final class SiteRepository
{
    /** @var list<Site> */
    private array $sites = [];

    /**
     * @return list<Site>
     */
    public function visibleTo(?string $user): array
    {
        return null === $user ? [] : $this->sites;
    }
}
