<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;

/**
 * A Doctrine listener: registered on Doctrine's event manager, absent from `debug:event-dispatcher`.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TotalsListener
{
    public function prePersist(): void
    {
    }
}
