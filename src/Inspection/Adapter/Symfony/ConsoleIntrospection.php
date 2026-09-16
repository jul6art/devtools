<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Transition;

/**
 * What the compiled application says about itself, in exactly six questions (ADR-0007) — whatever the
 * number of routes, commands or handlers.
 */
final readonly class ConsoleIntrospection
{
    /**
     * @param list<array{name: string, path: string, methods: string, controller: string|null}> $routes
     * @param array<string, string>                                                             $commands      class => command name
     * @param array<string, string|null>                                                        $handlers      class => handled message, when the container knows it
     * @param array<string, list<array{event: string, method: string}>>                         $listeners     class => what it listens to
     * @param array<string, StateMachine>                                                       $stateMachines
     * @param list<array{path: string, roles: list<string>}>                                    $accessControl
     */
    private function __construct(
        public array $routes,
        public array $commands,
        public array $handlers,
        public array $listeners,
        public array $stateMachines,
        public array $accessControl,
    ) {
    }

    public static function ask(SymfonyConsole $console): self
    {
        $routes = [];

        foreach ($console->json(['debug:router']) as $name => $route) {
            $routes[] = [
                'name' => (string) $name,
                'path' => Json::string($route, 'path') ?? '/',
                'methods' => Json::string($route, 'method') ?? 'ANY',
                'controller' => Json::string(Json::array($route, 'defaults'), '_controller'),
            ];
        }

        $commands = [];

        foreach (Json::array($console->json(['debug:container', '--tag=console.command']), 'definitions') as $definition) {
            $class = Json::string($definition, 'class');

            foreach (Json::array($definition, 'tags') as $tag) {
                $name = Json::string(Json::array($tag, 'parameters'), 'command');

                if (null !== $class && null !== $name && 'console.command' === Json::string($tag, 'name')) {
                    $commands[$class] = $name;
                }
            }
        }

        $handlers = [];

        foreach (Json::array($console->json(['debug:container', '--tag=messenger.message_handler']), 'definitions') as $definition) {
            $class = Json::string($definition, 'class');

            foreach (Json::array($definition, 'tags') as $tag) {
                if (null !== $class && 'messenger.message_handler' === Json::string($tag, 'name')) {
                    $handlers[$class] = Json::string(Json::array($tag, 'parameters'), 'handles');
                }
            }
        }

        $listeners = [];

        foreach ($console->json(['debug:event-dispatcher']) as $event => $eventListeners) {
            foreach (\is_array($eventListeners) ? $eventListeners : [] as $listener) {
                $class = Json::string($listener, 'class');

                if (null !== $class) {
                    $listeners[$class][] = ['event' => (string) $event, 'method' => Json::string($listener, 'name') ?? '__invoke'];
                }
            }
        }

        $stateMachines = [];

        foreach (Json::array($console->json(['debug:config', 'framework', 'workflows']), 'workflows') as $name => $workflow) {
            $places = array_values(array_filter(array_map(static fn (mixed $place): ?string => \is_string($place) ? $place : Json::string($place, 'name'), Json::array($workflow, 'places'))));
            $transitions = [];

            foreach (Json::array($workflow, 'transitions') as $key => $transition) {
                $ends = static fn (string $side): array => array_values(array_filter(array_map(static fn (mixed $end): ?string => \is_string($end) ? $end : Json::string($end, 'place'), Json::array($transition, $side))));
                $transitions[] = new Transition(Json::string($transition, 'name') ?? (string) $key, $ends('from'), $ends('to'));
            }

            $stateMachines[(string) $name] = new StateMachine((string) $name, $places, $transitions);
        }

        $accessControl = [];

        foreach ($console->json(['debug:config', 'security', 'access_control']) as $rule) {
            $path = Json::string($rule, 'path');

            if (null !== $path) {
                $accessControl[] = ['path' => $path, 'roles' => Json::strings(Json::array($rule, 'roles'))];
            }
        }

        return new self($routes, $commands, $handlers, $listeners, $stateMachines, $accessControl);
    }
}
