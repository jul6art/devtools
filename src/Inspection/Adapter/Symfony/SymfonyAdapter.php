<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Graph\DecisionExtractor;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\MechanismAttachment;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\TwigReferenceExtractor;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
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

    /**
     * The lifecycle events Doctrine fires for any entity: a listener on one of them runs inside every
     * workflow that reaches an entity.
     */
    private const array DOCTRINE_EVENTS = ['prePersist', 'postPersist', 'preUpdate', 'postUpdate', 'preRemove', 'postRemove', 'postLoad', 'preFlush', 'onFlush', 'postFlush', 'onClear', 'loadClassMetadata'];

    private const string TEMPLATES = 'templates';

    /**
     * The controllers API Platform answers its operations with (ADR-0051): 4.x, then 3.x. Its `not_exposed` one
     * answers 404 — an operation behind it is not a route.
     */
    private const array API_PLATFORM_CONTROLLERS = ['api_platform.symfony.main_controller', 'api_platform.action.placeholder'];

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

        $symfonyConsole = new SymfonyConsole($this->runner, $root->absolute($stack->root), CommandLine::split($config->symfonyConsole), $config->symfonyEnv, $config->symfonyTimeout);

        try {
            $console = ConsoleIntrospection::ask($symfonyConsole);
            [$confidence, $fallbackCause] = [Confidence::High, null];
        } catch (ConsoleFailed|\InvalidArgumentException $failure) {
            [$console, $confidence, $fallbackCause] = [null, Confidence::Medium, $failure->getMessage()];
        }

        $source = new WorkflowSource('native:symfony');
        $services = $files->existing(['config/services.yaml', 'config/services.php'], FileRole::Config);
        $candidates = [];

        // A listener is not a workflow: it is what can happen inside the workflows it intercepts (ADR-0043).
        $mechanisms = $this->mechanisms($console, $scanner, $root, $extractor);

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
                navigation: $this->navigation($root, $files, $extractor, $file, $route['method'], $route['name']),
                states: $states,
                extraTests: $files->testsRequesting($route['path']),
                confidence: $confidence,
                source: $source,
            );
        }

        $candidates = [...$candidates, ...$this->apiOperations($console, $scanner, $files, [...$routeConfiguration, ...$services], $confidence, $source)];

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

        $warnings = $scanner->warnings();
        $resources = $console instanceof ConsoleIntrospection ? [] : $scanner->apiResources();

        if ([] !== $resources) {
            $warnings[] = \sprintf('%d API Platform resources (%s): their operations are read from the console only, which did not answer.', \count($resources), implode(', ', $resources));
        }

        return new AdapterResult($candidates, [self::TEMPLATES], $warnings, $fallbackCause, consoleCalls: $symfonyConsole->calls(), mechanisms: $mechanisms);
    }

    /**
     * The API Platform operations of the project's resources, each a route (ADR-0051): declared by its resource
     * class, which reaches its provider and processor; its security, provider and processor read in the resource's
     * attributes. Without the console, none: the path of an operation without a URI template is API Platform's
     * to compute, and guessing it would invent a fact.
     *
     * @param list<FileRef> $configuration
     *
     * @return list<EntryPointCandidate>
     */
    private function apiOperations(?ConsoleIntrospection $console, StaticSymfonyScanner $scanner, ProjectFiles $files, array $configuration, Confidence $confidence, WorkflowSource $source): array
    {
        if (!$console instanceof ConsoleIntrospection) {
            return [];
        }

        $candidates = [];
        $structural = [...$configuration, ...$files->existing(['config/packages/api_platform.yaml'], FileRole::Config)];

        foreach ($console->routes as $route) {
            $file = null === $route['resource'] || !\in_array($route['controller'], self::API_PLATFORM_CONTROLLERS, true) ? null : $scanner->fileOf($route['resource']);

            // A resource of the vendor (API Platform's errors) is not the project's.
            if (!$file instanceof FileRef) {
                continue;
            }

            $operation = self::operationOf($scanner->apiOperations((string) $route['resource']), $route);
            $attributes = array_filter([
                'path' => $route['path'],
                'methods' => 'ANY' === $route['methods'] ? null : $route['methods'],
                'security' => implode(', ', array_values(array_unique([...self::accessControlRoles($console, $route['path']), ...$operation['security'] ?? []]))),
                'provider' => $operation['provider'] ?? null,
                'processor' => $operation['processor'] ?? null,
            ], static fn (?string $value): bool => null !== $value && '' !== $value);

            $candidates[] = new EntryPointCandidate(
                type: WorkflowType::routes(),
                entryPoint: new EntryPoint('api-operation', $route['name'], $file, $attributes),
                title: trim(('ANY' === $route['methods'] ? '' : $route['methods']).' '.$route['path']),
                structuralFiles: $structural,
                extraTests: $files->testsRequesting((string) preg_replace('/\.\{_format\}$/', '', $route['path'])),
                confidence: $confidence,
                source: $source,
            );
        }

        return $candidates;
    }

    /**
     * The operation of the resource behind a route: by its name, then by its URI template and its method, then by
     * its kind — a collection or not — and its method.
     *
     * @param list<array{method: string, collection: bool, name: string|null, uriTemplate: string|null, provider: string|null, processor: string|null, security: list<string>}> $operations
     * @param array{name: string, path: string, methods: string}                                                                                                                $route
     *
     * @return array{method: string, collection: bool, name: string|null, uriTemplate: string|null, provider: string|null, processor: string|null, security: list<string>}|null
     */
    private static function operationOf(array $operations, array $route): ?array
    {
        $withoutFormat = static fn (string $path): string => str_replace(['{._format}', '.{_format}'], '', $path);
        $methods = explode('|', $route['methods']);
        $collection = str_ends_with($route['name'], '_collection');

        foreach ($operations as $operation) {
            if ($operation['name'] === $route['name']) {
                return $operation;
            }
        }

        foreach ($operations as $operation) {
            if (null !== $operation['uriTemplate'] && \in_array($operation['method'], $methods, true) && str_ends_with($withoutFormat($route['path']), $withoutFormat($operation['uriTemplate']))) {
                return $operation;
            }
        }

        foreach ($operations as $operation) {
            if (null === $operation['name'] && null === $operation['uriTemplate'] && \in_array($operation['method'], $methods, true) && ('GET' !== $operation['method'] || $operation['collection'] === $collection)) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * The listeners of the project, turned into mechanisms and pointed at the workflows they intercept.
     *
     * The rules are those of ADR-0043: kernel events run inside every route, console events inside every
     * command, Doctrine events inside the workflows that reach an entity — and a targeted entity
     * listener only inside the workflows that reach *its* entity.
     *
     * @return list<MechanismAttachment>
     */
    private function mechanisms(?ConsoleIntrospection $console, StaticSymfonyScanner $scanner, ProjectRoot $root, PhpReferenceExtractor $extractor): array
    {
        $decisions = new DecisionExtractor($extractor);
        $attachments = [];

        foreach (self::listeners($console, $scanner) as $class => $listens) {
            $file = $scanner->fileOf($class);

            if (!$file instanceof FileRef) {
                continue;
            }

            $methods = array_values(array_unique(array_map(static fn (array $listen): string => $listen['method'], $listens)));
            $written = DecisionPoint::branching($decisions->extract($root->absolute($file), $file, $methods));
            $entities = $scanner->entityListenerTargets($class);
            $seen = [];

            foreach ($listens as $listen) {
                if (isset($seen[$listen['event']])) {
                    continue;
                }

                $seen[$listen['event']] = true;
                $mechanism = new Mechanism('listener', $class, $listen['event'], $file, $listen['priority'] ?? null, $written);
                $attachments[] = self::attach($mechanism, $listen['event'], $entities, $scanner);
            }
        }

        // ADR-0044: a voter decides access inside the workflows that ask for one of its attributes.
        foreach ($scanner->voters() as $class => $attributes) {
            $file = $scanner->fileOf($class);

            if ($file instanceof FileRef && [] !== $attributes) {
                $voter = new Mechanism('voter', $class, implode(', ', $attributes), $file, decisions: $decisions->votes($root->absolute($file), $file));
                $attachments[] = new MechanismAttachment($voter, grants: $attributes);
            }
        }

        return $attachments;
    }

    /**
     * The console knows what the dispatcher holds; the attributes know what Doctrine holds. Neither
     * alone is the whole truth, so the two are merged — the console wins on an event both describe,
     * because it reports the priority the container actually compiled.
     *
     * @return array<string, list<array{event: string, method: string, priority: int|null}>>
     */
    private static function listeners(?ConsoleIntrospection $console, StaticSymfonyScanner $scanner): array
    {
        $declared = $scanner->listeners();

        if (!$console instanceof ConsoleIntrospection) {
            return $declared;
        }

        $merged = $console->listeners;

        foreach ($declared as $class => $listens) {
            $known = array_flip(array_map(static fn (array $listen): string => $listen['event'], $merged[$class] ?? []));

            foreach ($listens as $listen) {
                if (!isset($known[$listen['event']])) {
                    $merged[$class][] = $listen;
                }
            }
        }

        return $merged;
    }

    /**
     * @param list<string> $entities the entities a targeted Doctrine listener declares
     */
    private static function attach(Mechanism $mechanism, string $event, array $entities, StaticSymfonyScanner $scanner): MechanismAttachment
    {
        if (\in_array($event, self::KERNEL_EVENTS, true)) {
            return new MechanismAttachment($mechanism, WorkflowType::routes()->name);
        }

        if (str_starts_with($event, 'console.')) {
            return new MechanismAttachment($mechanism, WorkflowType::commands()->name);
        }

        if ([] !== $entities) {
            return new MechanismAttachment($mechanism, files: array_values(array_filter(array_map(
                static fn (string $entity): ?string => $scanner->fileOf($entity)?->path,
                $entities,
            ))));
        }

        if (\in_array($event, self::DOCTRINE_EVENTS, true)) {
            return new MechanismAttachment($mechanism, anyEntity: true);
        }

        // `workflow.work_order.entered.assigned` runs inside the workflows that drive that state machine,
        // and only those: attaching it to every route is exactly the noise ADR-0043 removes.
        if (1 === preg_match('/^workflow\.([^.]+)\./', $event, $matches)) {
            return new MechanismAttachment($mechanism, stateMachine: $matches[1]);
        }

        // Anything else is a domain event, a class of the project: the workflows that traverse it are the
        // ones that fire it. A listener nothing reaches is attached nowhere, and its file says so under
        // "Non couvert".
        $declaring = $scanner->fileOf($event);

        return new MechanismAttachment($mechanism, files: [$declaring instanceof FileRef ? $declaring->path : $mechanism->declaredIn->path]);
    }

    /**
     * @return list<array{name: string, path: string, methods: string, class: string, method: string}>
     */
    private function consoleRoutes(ConsoleIntrospection $console): array
    {
        $routes = [];

        foreach ($console->routes as $route) {
            if (null === $route['controller'] || null !== $route['resource']) {
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
