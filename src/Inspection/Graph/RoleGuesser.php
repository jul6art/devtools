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
            str_ends_with($name, '.twig') || str_ends_with($name, '.blade.php') => FileRole::Template,
            str_ends_with($name, 'Test.php') || $in('tests', 'Tests') => FileRole::Test,
            str_ends_with($name, 'Controller.php') || $in('Controller') => FileRole::Controller,
            $in('Form') && str_ends_with($name, 'Type.php') => FileRole::Form,
            str_ends_with($name, 'Repository.php') || $in('Repository') => FileRole::Repository,
            $in('Entity') => FileRole::Entity,
            str_ends_with($name, 'Listener.php') || str_ends_with($name, 'Subscriber.php') || $in('EventListener', 'EventSubscriber') => FileRole::Listener,
            str_ends_with($name, 'Handler.php') || $in('MessageHandler') => FileRole::Handler,
            $in('Message') => FileRole::Message,
            $in('Components', 'Component') => FileRole::Component,
            $in('config') || 1 === preg_match('/\.(ya?ml|xml|neon)$/', $name) => FileRole::Config,
            $in('Service') => FileRole::Service,
            default => FileRole::Other,
        };
    }
}
