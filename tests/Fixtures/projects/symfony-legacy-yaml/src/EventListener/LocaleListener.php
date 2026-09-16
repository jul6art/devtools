<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

final class LocaleListener
{
    public function __invoke(RequestEvent $event): void
    {
        $event->getRequest()->setLocale('fr');
    }
}
