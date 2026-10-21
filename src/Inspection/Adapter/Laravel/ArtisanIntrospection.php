<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\Json;

/**
 * What the booted application says about itself, in three questions to Artisan (ADR-0030) — whatever the
 * number of routes, commands or listeners.
 */
final readonly class ArtisanIntrospection
{
    /**
     * @param list<array{name: string|null, methods: string, uri: string, action: string, middleware: list<string>}> $routes
     * @param list<string>                                                                                           $commands  every command name Artisan knows, the framework's included
     * @param array<string, list<string>>|null                                                                       $listeners event => its listeners (`Class@method`), or null when `event:list` could not answer
     */
    private function __construct(
        public array $routes,
        public array $commands,
        public ?array $listeners,
        public ?string $listenersCause = null,
    ) {
    }

    public static function ask(Artisan $artisan): self
    {
        $routes = [];

        foreach ($artisan->json(['route:list', '--json']) as $route) {
            $uri = Json::string($route, 'uri');
            $action = Json::string($route, 'action');

            if (null !== $uri && null !== $action) {
                $routes[] = [
                    'name' => Json::string($route, 'name'),
                    'methods' => Json::string($route, 'method') ?? 'GET',
                    'uri' => $uri,
                    'action' => $action,
                    'middleware' => Json::strings(Json::array(\is_array($route) ? ['m' => $route['middleware'] ?? null] : [], 'm')),
                ];
            }
        }

        $commands = [];

        foreach (Json::array($artisan->json(['list', '--format=json']), 'commands') as $command) {
            $name = Json::string($command, 'name');

            if (null !== $name) {
                $commands[] = $name;
            }
        }

        // event:list answers in JSON since Laravel 11; an older or broken one leaves the listeners to the
        // static scan rather than the whole stack.
        try {
            $listeners = [];

            foreach ($artisan->json(['event:list', '--json']) as $event) {
                $name = Json::string($event, 'event');

                if (null !== $name) {
                    $listeners[$name] = Json::strings(Json::array($event, 'listeners'));
                }
            }

            return new self($routes, $commands, $listeners);
        } catch (ConsoleFailed $failure) {
            return new self($routes, $commands, null, $failure->getMessage());
        }
    }
}
