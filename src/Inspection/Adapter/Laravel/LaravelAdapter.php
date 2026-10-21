<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\CommandLine;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRunnerInterface;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProcessConsoleRunner;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProjectFiles;
use Jul6Art\DevTools\Inspection\Graph\BladeReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\DecisionExtractor;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\MechanismAttachment;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The Laravel adapter (ADR-0030): Artisan is the source of truth for what the booted application holds —
 * routes, commands, listeners — and the code completes it and stands in for it, as the console does for
 * Symfony (ADR-0007). Three Artisan processes per scan, whatever the size of the project.
 */
final readonly class LaravelAdapter implements AdapterInterface
{
    private const string VIEWS = 'resources/views';

    private const array ALL_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Laravel's own middlewares that say who may reach a route, by the alias a project writes.
     */
    private const array SECURITY_MIDDLEWARE = [
        'Illuminate\Auth\Middleware\Authenticate' => 'auth',
        'Illuminate\Auth\Middleware\AuthenticateWithBasicAuth' => 'auth.basic',
        'Illuminate\Auth\Middleware\Authorize' => 'can',
        'Illuminate\Auth\Middleware\EnsureEmailIsVerified' => 'verified',
        'Illuminate\Auth\Middleware\RequirePassword' => 'password.confirm',
        'Illuminate\Routing\Middleware\ValidateSignature' => 'signed',
        'Illuminate\Auth\Middleware\RedirectIfAuthenticated' => 'guest',
    ];

    /**
     * Aliases a project writes in its route files, and the groups and plumbing that say nothing about access.
     */
    private const array SECURITY_ALIASES = ['auth', 'auth.basic', 'can', 'verified', 'password.confirm', 'signed', 'guest'];

    public function __construct(private ConsoleRunnerInterface $runner = new ProcessConsoleRunner())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'laravel';
    }

    #[\Override]
    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $scanner = new StaticLaravelScanner($root, $stack, $extractor);
        $files = new ProjectFiles($root, $stack);
        $artisan = new Artisan($this->runner, $root->absolute($stack->root), CommandLine::split($config->laravelArtisan), $config->laravelEnv, $config->laravelTimeout);
        $warnings = [];

        try {
            $introspection = ArtisanIntrospection::ask($artisan);
            [$confidence, $fallbackCause] = [Confidence::High, null];

            if (null !== $introspection->listenersCause) {
                $warnings[] = 'The listeners come from the code: '.strtok($introspection->listenersCause, "\n");
            }
        } catch (ConsoleFailed|\InvalidArgumentException $failure) {
            [$introspection, $confidence, $fallbackCause] = [null, Confidence::Medium, $failure->getMessage()];
        }

        $source = new WorkflowSource('native:laravel');
        // The service providers are what config/services.yaml is to Symfony: the wiring every route and command runs on.
        $providers = [...$files->existing(['bootstrap/providers.php'], FileRole::Config), ...array_map(static fn (FileRef $file): FileRef => new FileRef($file->path, FileRole::Config), $files->in('app/Providers', FileRole::Config, ['php']))];
        $routing = [...$files->existing(['bootstrap/app.php'], FileRole::Config), ...array_values(array_filter($files->in('routes', FileRole::Config, ['php']), static fn (FileRef $file): bool => 'console.php' !== basename($file->path))), ...$providers];
        $candidates = [];

        foreach ($this->routes($introspection, $scanner) as $route) {
            $candidates[] = $this->routeCandidate($route, $root, $files, $scanner, $extractor, $routing, $confidence, $source);
        }

        $commands = $this->commands($introspection, $scanner);

        foreach ($commands as $name => ['class' => $class, 'file' => $file]) {
            $candidates[] = new EntryPointCandidate(WorkflowType::commands(), new EntryPoint('command', $name, $file), $name, null === $class ? null : 'handle', $providers, confidence: $confidence, source: $source);
        }

        $listeners = $introspection->listeners ?? $scanner->listeners();
        $listenerClasses = array_values(array_unique(array_map(static fn (string $listener): string => explode('@', $listener)[0], array_merge([], ...array_values($listeners)))));
        $queue = $files->existing(['config/queue.php'], FileRole::Config);
        $scheduled = [];

        foreach ($scanner->schedule() as $task) {
            if ('job' === $task['kind']) {
                $scheduled[$task['target']] = $task['frequency'];

                continue;
            }

            $command = $commands[$task['target']] ?? null;
            $candidates[] = new EntryPointCandidate(
                WorkflowType::async(),
                new EntryPoint('scheduled', $task['target'], $task['file'], ['schedule' => $task['frequency']]),
                $task['target'].' ('.$task['frequency'].')',
                structuralFiles: [$task['file']],
                dependsOn: null === $command ? [] : [new EntryPoint('command', $task['target'], $command['file'])],
                confidence: Confidence::High,
                source: $source,
            );
        }

        foreach ($scanner->jobs($listenerClasses) as $job) {
            $file = $scanner->fileOf($job);

            if ($file instanceof FileRef) {
                $attributes = isset($scheduled[$job]) ? ['schedule' => $scheduled[$job]] : [];
                $candidates[] = new EntryPointCandidate(WorkflowType::async(), new EntryPoint('job', $job, $file, $attributes), self::shortName($job), 'handle', $queue, confidence: Confidence::High, source: $source);
            }
        }

        foreach ($scanner->components() as $class => $kind) {
            $file = $scanner->fileOf($class);

            if ($file instanceof FileRef) {
                $view = $files->existing([self::VIEWS.'/'.('livewire' === $kind ? 'livewire' : 'components').'/'.self::kebab(self::shortName($class)).'.blade.php'], FileRole::Template);
                $candidates[] = new EntryPointCandidate(WorkflowType::ui(), new EntryPoint('component', $class, $file, ['live' => 'livewire' === $kind ? 'true' : 'false']), self::shortName($class), structuralFiles: $view, confidence: Confidence::High, source: $source);
            }
        }

        foreach (['migrations' => 'database/migrations', 'seeders' => 'database/seeders'] as $family => $directory) {
            $familyFiles = $files->in($directory, FileRole::Other, ['php']);

            if ([] !== $familyFiles) {
                $candidates[] = new EntryPointCandidate(WorkflowType::data(), new EntryPoint(rtrim($family, 's'), $family, $familyFiles[0]), ucfirst($family), structuralFiles: $familyFiles, confidence: Confidence::High, source: $source);
            }
        }

        return new AdapterResult(
            $candidates,
            [self::VIEWS],
            [...$warnings, ...$scanner->warnings()],
            $fallbackCause,
            consoleCalls: $artisan->calls(),
            mechanisms: $this->mechanisms($listeners, $scanner, $root, $extractor),
        );
    }

    /**
     * The routes of the project — Artisan's, or the route files' when it cannot answer — with the file
     * declaring each: its controller, or the route file of a closure. A route of a package is not the
     * project's workflow.
     *
     * @return list<array{name: string, title: string, path: string, methods: string, file: FileRef, method: string|null, middleware: list<string>}>
     */
    private function routes(?ArtisanIntrospection $introspection, StaticLaravelScanner $scanner): array
    {
        $declared = $scanner->routes();
        $closures = [];

        foreach ($declared as $route) {
            $closures[self::methods($route['methods']).' '.$route['uri']] = $route['file'];
        }

        $routes = [];

        foreach ($introspection->routes ?? $declared as $route) {
            [$class, $method] = str_contains($route['action'], '@') ? explode('@', $route['action'], 2) : [$route['action'], '__invoke'];
            $methods = self::methods($route['methods']);
            $uri = trim($route['uri'], '/');

            if ('Closure' === $class) {
                $path = $closures[$methods.' '.$uri] ?? null;
                $file = null === $path ? null : new FileRef($path, FileRole::Config);
                $method = null;
            } else {
                $file = $scanner->fileOf($class);
            }

            if (!$file instanceof FileRef) {
                continue;
            }

            $verbs = 'ANY' === $methods ? 'any' : strtolower(explode('|', $methods)[0]);
            $routes[] = [
                'name' => $route['name'] ?? $verbs.'.'.('' === $uri ? 'root' : str_replace('/', '.', (string) preg_replace('/[{}?]/', '', $uri))),
                'title' => trim(('ANY' === $methods ? '' : $methods).' /'.$uri),
                'path' => '/'.$uri,
                'methods' => $methods,
                'file' => $file,
                'method' => $method,
                'middleware' => $route['middleware'],
            ];
        }

        return $routes;
    }

    /**
     * @param array{name: string, title: string, path: string, methods: string, file: FileRef, method: string|null, middleware: list<string>} $route
     * @param list<FileRef>                                                                                                                   $routing
     */
    private function routeCandidate(array $route, ProjectRoot $root, ProjectFiles $files, StaticLaravelScanner $scanner, PhpReferenceExtractor $extractor, array $routing, Confidence $confidence, WorkflowSource $source): EntryPointCandidate
    {
        $security = self::security($route['middleware']);
        $attributes = ['path' => $route['path']];

        if ('ANY' !== $route['methods']) {
            $attributes['methods'] = $route['methods'];
        }

        if ([] !== $security) {
            $attributes['security'] = implode(', ', $security);
        }

        return new EntryPointCandidate(
            type: WorkflowType::routes(),
            entryPoint: new EntryPoint('route', $route['name'], $route['file'], $attributes),
            title: $route['title'],
            method: $route['method'],
            structuralFiles: [...$routing, ...$this->policies($security, $scanner)],
            navigation: null === $route['method'] ? [] : $this->navigation($root, $files, $extractor, $route['file'], $route['method'], $route['name']),
            extraTests: $files->testsRequesting($route['path']),
            confidence: $confidence,
            source: $source,
        );
    }

    /**
     * Command name => the class and file declaring it: the project's commands Artisan lists, or every one the
     * code declares when Artisan cannot answer.
     *
     * @return array<string, array{class: string|null, file: FileRef}>
     */
    private function commands(?ArtisanIntrospection $introspection, StaticLaravelScanner $scanner): array
    {
        $declared = $scanner->commands();

        if (!$introspection instanceof ArtisanIntrospection) {
            return $declared;
        }

        return array_intersect_key($declared, array_flip($introspection->commands));
    }

    /**
     * Listeners are mechanisms of the workflows that dispatch their event (ADR-0043): those that traverse
     * the event's class. One the project does not declare — a package's — is not its own.
     *
     * @param array<string, list<string>> $listeners event => `Class@method`
     *
     * @return list<MechanismAttachment>
     */
    private function mechanisms(array $listeners, StaticLaravelScanner $scanner, ProjectRoot $root, PhpReferenceExtractor $extractor): array
    {
        $decisions = new DecisionExtractor($extractor);
        $attachments = [];

        foreach ($listeners as $event => $eventListeners) {
            foreach ($eventListeners as $listener) {
                [$class, $method] = str_contains($listener, '@') ? explode('@', $listener, 2) : [$listener, 'handle'];
                $file = $scanner->fileOf($class);

                if (!$file instanceof FileRef) {
                    continue;
                }

                $mechanism = new Mechanism('listener', $class, $event, $file, decisions: DecisionPoint::branching($decisions->extract($root->absolute($file), $file, [$method])));
                $declaring = $scanner->fileOf($event);
                $attachments[] = new MechanismAttachment($mechanism, files: [$declaring instanceof FileRef ? $declaring->path : $file->path]);
            }
        }

        return $attachments;
    }

    /**
     * `auth`, `can:view,order`, `verified` — what a route's middleware says about who may reach it, whether
     * Artisan resolved them to classes or the route file wrote their aliases.
     *
     * @param list<string> $middleware
     *
     * @return list<string>
     */
    private static function security(array $middleware): array
    {
        $security = [];

        foreach ($middleware as $entry) {
            $colon = strpos($entry, ':');
            $name = false === $colon ? $entry : substr($entry, 0, $colon);
            $parameters = false === $colon ? null : substr($entry, $colon + 1);
            $alias = self::SECURITY_MIDDLEWARE[$name] ?? (\in_array($name, self::SECURITY_ALIASES, true) ? $name : null);

            if (null !== $alias) {
                $security[] = null === $parameters ? $alias : $alias.':'.$parameters;
            }
        }

        return array_values(array_unique($security));
    }

    /**
     * The policies a route's `can:` middleware asks, by Laravel's convention: `can:view,order` and
     * `can:create,App\Models\Order` both name `app/Policies/OrderPolicy.php`.
     *
     * @param list<string> $security
     *
     * @return list<FileRef>
     */
    private function policies(array $security, StaticLaravelScanner $scanner): array
    {
        $policies = [];

        foreach ($security as $entry) {
            if (str_starts_with($entry, 'can:') && str_contains($entry, ',')) {
                $model = explode(',', $entry, 2)[1];
                $policy = $scanner->policyOf(str_contains($model, '\\') ? $model : ucfirst($model));

                if ($policy instanceof FileRef) {
                    $policies[$policy->path] = $policy;
                }
            }
        }

        return array_values($policies);
    }

    /**
     * Routes a route leads to: the routes its method redirects to, and the links of the views it renders and
     * includes — not of their layouts, whose links belong to every page.
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

        $blade = new BladeReferenceExtractor();
        $pending = $references->templates;
        $seen = [];

        while ([] !== $pending) {
            $view = array_shift($pending);
            $file = $files->existing([self::VIEWS.'/'.$view], FileRole::Template)[0] ?? null;

            if (isset($seen[$view]) || !$file instanceof FileRef) {
                continue;
            }

            $seen[$view] = true;

            try {
                $found = $blade->extract($root->absolute($file));
            } catch (PathOutsideProject) {
                continue;
            }

            foreach ($found->routes as $target) {
                $edges[$target.'|link'] = new Edge($target, 'link');
            }

            $pending = [...$pending, ...array_diff($found->views, $found->parents)];
        }

        ksort($edges, \SORT_STRING);

        return array_values(array_filter($edges, static fn (Edge $edge): bool => $edge->target !== $self));
    }

    /**
     * `GET|HEAD` → `GET`; every method → `ANY`.
     */
    private static function methods(string $methods): string
    {
        $list = array_values(array_diff(explode('|', strtoupper($methods)), ['HEAD']));

        return [] === array_diff(self::ALL_METHODS, $list) || \in_array('ANY', $list, true) ? 'ANY' : implode('|', $list);
    }

    private static function shortName(string $class): string
    {
        return ltrim(strrchr('\\'.$class, '\\') ?: $class, '\\');
    }

    private static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name));
    }
}
