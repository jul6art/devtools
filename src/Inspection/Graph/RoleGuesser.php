<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRole;

/**
 * The role of a file, from its path and name only (ADR-0006). Unknown is `other`, never a guess.
 */
final class RoleGuesser
{
    public static function guess(string $path): FileRole
    {
        $name = basename($path);
        $segments = explode('/', \dirname($path));
        $in = static fn (string ...$directories): bool => [] !== array_intersect($directories, $segments);

        return match (true) {
            str_ends_with($name, '.twig') || str_ends_with($name, '.blade.php') || str_ends_with($name, '.component.html') || str_ends_with($name, '.page.html') => FileRole::Template,
            // Angular's naming (ADR-0032): `order-list.component.ts`, `tab1.page.ts`, `order.service.ts`.
            1 === preg_match('/\.(component|page)\.[jt]s$/', $name) => FileRole::Component,
            1 === preg_match('/\.service\.[jt]s$/', $name) => FileRole::Service,
            1 === preg_match('/\.routes\.[jt]s$|-routing\.module\.[jt]s$|^app\.config\.[jt]s$/', $name) => FileRole::Config,
            str_ends_with($name, 'Test.php') || $in('tests', 'Tests') || 1 === preg_match('/\.(spec|test)\.[cm]?[jt]sx?$/', $name) => FileRole::Test,
            str_ends_with($name, 'Controller.php') || $in('Controller', 'controllers') || 1 === preg_match('/Controller\.[cm]?[jt]s$/', $name) => FileRole::Controller,
            $in('Form') && str_ends_with($name, 'Type.php') => FileRole::Form,
            str_ends_with($name, 'Repository.php') || $in('Repository') => FileRole::Repository,
            // Laravel's conventions (ADR-0030): Eloquent models, listeners, queued jobs.
            $in('Entity', 'Models') => FileRole::Entity,
            str_ends_with($name, 'Listener.php') || str_ends_with($name, 'Subscriber.php') || $in('EventListener', 'EventSubscriber', 'Listeners') => FileRole::Listener,
            str_ends_with($name, 'Handler.php') || $in('MessageHandler', 'Jobs') => FileRole::Handler,
            $in('Message', 'Events') => FileRole::Message,
            $in('Components', 'Component', 'Livewire') => FileRole::Component,
            $in('config') || 1 === preg_match('/\.(ya?ml|xml|neon)$/', $name) => FileRole::Config,
            $in('Service', 'services') || 1 === preg_match('/Service\.[cm]?[jt]s$/', $name) => FileRole::Service,
            default => FileRole::Other,
        };
    }
}
