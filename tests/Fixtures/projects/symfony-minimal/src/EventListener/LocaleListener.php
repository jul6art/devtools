<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(event: 'kernel.request', priority: 20)]
final class LocaleListener
{
    public function __invoke(RequestEvent $event): void
    {
        $event->getRequest()->setLocale('fr');
    }
}
