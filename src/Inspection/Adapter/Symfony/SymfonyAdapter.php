<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\TwigReferenceExtractor;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The Symfony adapter: the project's console is the source of truth, the attributes complete and stand in
 * for it (ADR-0007). The same code runs standalone and through the bundle.
 */
final readonly class SymfonyAdapter implements AdapterInterface
{
    private const array KERNEL_EVENTS = ['kernel.request', 'kernel.controller', 'kernel.controller_arguments', 'kernel.view', 'kernel.response', 'kernel.finish_request', 'kernel.exception', 'kernel.terminate'];

    private const string TEMPLATES = 'templates';

    public function __construct(private ConsoleRunnerInterface $runner = new ProcessConsoleRunner())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'symfony';
    }

    #[\Override]
    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $scanner = new StaticSymfonyScanner($root, $stack, $extractor);
        $files = new ProjectFiles($root, $stack);

        try {
            $console = ConsoleIntrospection::ask(new SymfonyConsole($this->runner, $root->absolute($stack->root), CommandLine::split($config->symfonyConsole), $config->symfonyEnv, $config->symfonyTimeout));
            [$confidence, $fallbackCause] = [Confidence::High, null];
        } catch (ConsoleFailed|\InvalidArgumentException $failure) {
            [$console, $confidence, $fallbackCause] = [null, Confidence::Medium, $failure->getMessage()];
        }

        $source = new WorkflowSource('native:symfony');
        $services = $files->existing(['config/services.yaml', 'config/services.php'], FileRole::Config);
        $candidates = [];

        // Listeners first: routes depend on the kernel ones.
        $kernelListeners = [];

        foreach ($console instanceof ConsoleIntrospection ? $console->listeners : $scanner->listeners() as $class => $listens) {
            $file = $scanner->fileOf($class);

            if (!$file instanceof FileRef) {
                continue;
            }

            $methods = array_values(array_unique(array_map(static fn (array $listen): string => $listen['method'], $listens)));
            $events = array_values(array_unique(array_map(static fn (array $listen): string => $listen['event'], $listens)));
            sort($events);
            $entryPoint = new EntryPoint('listener', $class, $file, ['events' => implode(', ', $events)]);

            $candidates[] = new EntryPointCandidate(WorkflowType::events(), $entryPoint, implode(', ', $events).' → '.self::shortName($class), 1 === \count($methods) ? $methods[0] : null, $services, confidence: $confidence, source: $source);

            if ([] !== array_intersect($events, self::KERNEL_EVENTS)) {
                $kernelListeners[] = $entryPoint;
            }
        }

        $routeConfiguration = [...$files->existing(['config/routes.yaml', 'config/routes.php'], FileRole::Config), ...$files->in('config/routes', FileRole::Config), ...$files->existing(['config/packages/security.yaml'], FileRole::Config)];
        $workflowConfiguration = $files->declaring('workflows');

        foreach ($console instanceof ConsoleIntrospection ? $this->consoleRoutes($console) : $scanner->routes() as $route) {
            $file = $scanner->fileOf($route['class']);

            // Routes of vendor controllers (profiler, bundles) are not the project's workflows.
            if (!$file instanceof FileRef) {
                continue;
            }

            $roles = array_values(array_unique([...$console instanceof ConsoleIntrospection ? self::accessControlRoles($console, $route['path']) : [], ...$scanner->grantedRoles($route['class'], $route['method'])]));
            $states = $console instanceof ConsoleIntrospection
                ? self::stateMachine($console, $scanner->workflowParameters($route['class'], $route['method'])) ?? self::machineOfEntity($console, $extractor->extract($root->absolute($file), [$route['method']])->classes)
                : null;
            $attributes = ['path' => $route['path']];

            if ('ANY' !== $route['methods']) {
                $attributes['methods'] = $route['methods'];
            }

            if ([] !== $roles) {
                $attributes['security'] = implode(', ', $roles);
            }

            $candidates[] = new EntryPointCandidate(
                type: WorkflowType::routes(),
                entryPoint: new EntryPoint('route', $route['name'], $file, $attributes),
                title: trim(('ANY' === $route['methods'] ? '' : $route['methods']).' '.$route['path']),
                method: $route['method'],
                structuralFiles: [...$routeConfiguration, ...$services, ...($states instanceof StateMachine ? $workflowConfiguration : [])],
                dependsOn: $kernelListeners,
                navigation: $this->navigation($root, $files, $extractor, $file, $route['method'], $route['name']),
                states: $states,
                extraTests: $files->testsRequesting($route['path']),
                confidence: $confidence,
                source: $source,
            );
        }

        foreach ($console instanceof ConsoleIntrospection ? $console->commands : $scanner->commands() as $class => $command) {
            $file = $scanner->fileOf($class);

            if ($file instanceof FileRef) {
                $candidates[] = new EntryPointCandidate(WorkflowType::commands(), new EntryPoint('command', $command, $file), $command, structuralFiles: $services, confidence: $confidence, source: $source);
            }
        }

        $messenger = [...$files->existing(['config/packages/messenger.yaml'], FileRole::Config), ...$files->declaring('messenger')];
        $handlers = $console instanceof ConsoleIntrospection ? array_map(static fn (): string => '__invoke', $console->handlers) : $scanner->handlers();

        foreach ($handlers as $class => $method) {
            $file = $scanner->fileOf($class);
            $message = ($console?->handlers[$class] ?? null) ?? $scanner->messageOf($class, $method);

            if ($file instanceof FileRef) {
                $candidates[] = new EntryPointCandidate(WorkflowType::async(), new EntryPoint('message-handler', $class, $file, null === $message ? [] : ['message' => $message]), self::shortName($message ?? $class), $method, [...$services, ...$messenger], confidence: $confidence, source: $source);
            }
        }

        foreach ($scanner->scheduledTasks() as $class => $schedule) {
            $file = $scanner->fileOf($class);

            if ($file instanceof FileRef) {
                $candidates[] = new EntryPointCandidate(WorkflowType::async(), new EntryPoint('scheduled', $class, $file, ['schedule' => $schedule]), self::shortName($class).' ('.$schedule.')', structuralFiles: $services, confidence: $confidence, source: $source);
            }
        }

        foreach ($scanner->components() as $class => $live) {
            $file = $scanner->fileOf($class);

            if ($file instanceof FileRef) {
                $template = $files->existing([self::TEMPLATES.'/components/'.self::shortName($class).'.html.twig'], FileRole::Template);
                $candidates[] = new EntryPointCandidate(WorkflowType::ui(), new EntryPoint('component', $class, $file, ['live' => $live ? 'true' : 'false']), self::shortName($class), structuralFiles: $template, confidence: $confidence, source: $source);
            }
        }

        foreach (['migrations' => 'migrations', 'fixtures' => 'src/DataFixtures'] as $family => $directory) {
            $familyFiles = $files->in($directory, FileRole::Other, ['php']);

            if ([] !== $familyFiles) {
                $candidates[] = new EntryPointCandidate(WorkflowType::data(), new EntryPoint(rtrim($family, 's'), $family, $familyFiles[0]), ucfirst($family), structuralFiles: $familyFiles, confidence: Confidence::High, source: $source);
            }
        }

        return new AdapterResult($candidates, [self::TEMPLATES], $scanner->warnings(), $fallbackCause);
    }

    /**
     * @return list<array{name: string, path: string, methods: string, class: string, method: string}>
     */
    private function consoleRoutes(ConsoleIntrospection $console): array
    {
        $routes = [];

        foreach ($console->routes as $route) {
            if (null === $route['controller']) {
                continue;
            }

            [$class, $method] = str_contains($route['controller'], '::') ? explode('::', $route['controller'], 2) : [$route['controller'], '__invoke'];
            $routes[] = ['name' => $route['name'], 'path' => $route['path'], 'methods' => $route['methods'], 'class' => $class, 'method' => $method];
        }

        return $routes;
    }

    /**
     * The first access_control rule matching the path, as Symfony applies it.
     *
     * @return list<string>
     */
    private static function accessControlRoles(ConsoleIntrospection $console, string $path): array
    {
        $concrete = (string) preg_replace('/\{[^}]+\}/', 'x', $path);

        foreach ($console->accessControl as $rule) {
            if (1 === @preg_match('{'.$rule['path'].'}', $concrete)) {
                return $rule['roles'];
            }
        }

        return [];
    }

    /**
     * The machine a route drives, found by Symfony's autowiring convention: `$orderStateMachine`,
     * `$orderWorkflow` → `order`.
     *
     * @param list<string> $parameters
     */
    private static function stateMachine(ConsoleIntrospection $console, array $parameters): ?StateMachine
    {
        foreach ($parameters as $parameter) {
            $name = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) preg_replace('/(StateMachine|Workflow)$/', '', $parameter)));

            if (isset($console->stateMachines[$name])) {
                return $console->stateMachines[$name];
            }
        }

        return null;
    }

    /**
     * The machine a route drives when it does not receive it by Symfony's convention: a project routinely
     * wraps `WorkflowInterface` in a service of its own, and the route is then recognised by the entity it
     * handles — the one the machine declares it supports.
     *
     * @param list<string> $classes the classes the route's method uses
     */
    private static function machineOfEntity(ConsoleIntrospection $console, array $classes): ?StateMachine
    {
        foreach ($console->supports as $machine => $entities) {
            if ([] !== array_intersect($entities, $classes) && isset($console->stateMachines[$machine])) {
                return $console->stateMachines[$machine];
            }
        }

        return null;
    }

    /**
     * Routes a route leads to: its redirects, and the links of the templates it renders and includes — not
     * of their layouts, whose links belong to every page.
     *
     * @return list<Edge>
     */
    private function navigation(ProjectRoot $root, ProjectFiles $files, PhpReferenceExtractor $extractor, FileRef $controller, string $method, string $self): array
    {
        $references = $extractor->extract($root->absolute($controller), [$method]);
        $edges = [];

        foreach ($references->routes as $target) {
            $edges[$target.'|redirect'] = new Edge($target, 'redirect');
        }

        $twig = new TwigReferenceExtractor();
        $pending = $references->templates;
        $seen = [];

        while ([] !== $pending) {
            $template = array_shift($pending);
            $file = $files->existing([self::TEMPLATES.'/'.$template], FileRole::Template)[0] ?? null;

            if (isset($seen[$template]) || !$file instanceof FileRef) {
                continue;
            }

            $seen[$template] = true;
            $found = $twig->extract($root->absolute($file));

            foreach ($found->routes as $target) {
                $edges[$target.'|link'] = new Edge($target, 'link');
            }

            $pending = [...$pending, ...array_diff($found->templates, $found->parents)];
        }

        return array_values(array_filter($edges, static fn (Edge $edge): bool => $edge->target !== $self));
    }

    private static function shortName(string $class): string
    {
        return ltrim(strrchr('\\'.$class, '\\') ?: $class, '\\');
    }
}
