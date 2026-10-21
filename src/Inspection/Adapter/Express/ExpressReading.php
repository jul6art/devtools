<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Express;

use Jul6Art\DevTools\Inspection\Node\NodeFacts;

/**
 * What the facts of an Express application mean (ADR-0033): its applications and routers, how they are
 * mounted, the routes each one declares — in CommonJS and in ESM alike. The Node bridge read; this decides.
 *
 * @internal
 */
final class ExpressReading
{
    public const array VERBS = ['get', 'post', 'put', 'patch', 'delete', 'all', 'options', 'head'];

    /**
     * @var array<string, array{file: string, app: bool}> `file#variable` => what it is
     */
    private array $routers = [];

    /**
     * @var list<array{parent: string, prefix: string, child: string, middleware: list<string>}>
     */
    private array $mounts = [];

    /**
     * @var array<string, list<string>> router => the middleware it applies to all its routes
     */
    private array $routerMiddleware = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @param list<string> $files
     */
    public function __construct(private readonly NodeFacts $facts, private readonly array $files)
    {
        foreach ($files as $file) {
            $this->declare($file);
        }

        foreach ($files as $file) {
            $this->mount($file);
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Every route, at every path its router is mounted at.
     *
     * @return list<array{name: string, title: string, path: string, verb: string, declaredIn: string, inApplication: bool, middleware: list<string>, chain: list<string>, literal: bool}>
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->files as $file) {
            foreach ($this->facts->calls($file) as $call) {
                [$owner, $verb, $path] = $this->routeCall($file, $call);

                if (null === $owner) {
                    continue;
                }

                $arguments = $call['arguments'];
                $literal = null !== $path;
                $handler = array_pop($arguments);
                $middleware = array_values(array_filter(array_map($this->nameOf(...), \array_slice($arguments, null === $path || str_contains($call['callee'], '.route(') ? 0 : 1))));

                if (!$literal) {
                    $this->warnings[] = \sprintf('%s:%d: a route path computed at run time; its workflow has a medium confidence.', $file, $call['line']);
                }

                foreach ($this->prefixes($owner) as [$prefix, $inherited, $chain]) {
                    $full = self::join($prefix, $path ?? '*');
                    $routes[] = [
                        'name' => $verb.'.'.self::slug($full),
                        'title' => ('all' === $verb ? '' : strtoupper($verb).' ').$full,
                        'path' => $full,
                        'verb' => $verb,
                        'declaredIn' => $declaredIn = $this->declaringFile($file, $handler),
                        // An inline handler of the file creating the application: that file imports every router.
                        'inApplication' => $declaredIn === $file && ($this->routers[$owner]['app'] ?? false),
                        'middleware' => array_values(array_unique([...$inherited, ...$this->routerMiddleware[$owner] ?? [], ...$middleware])),
                        'chain' => array_values(array_unique([...$chain, $file])),
                        'literal' => $literal && !str_contains($prefix, '*'),
                    ];
                }
            }
        }

        $unique = [];

        foreach ($routes as $route) {
            $unique[$route['name']] ??= $route;
        }

        ksort($unique, \SORT_STRING);

        return array_values($unique);
    }

    /**
     * `new Worker('emails', …)` of BullMQ: queue => file.
     *
     * @return array<string, string>
     */
    public function workers(): array
    {
        $workers = [];

        foreach ($this->files as $file) {
            foreach ($this->facts->news($file) as $new) {
                $queue = NodeFacts::text($new['arguments'][0] ?? null);

                if (null !== $queue && $this->fromPackage($file, explode('.', $new['class'])[0], 'bullmq')) {
                    $workers[$queue] = $file;
                }
            }
        }

        ksort($workers, \SORT_STRING);

        return $workers;
    }

    /**
     * `cron.schedule('0 2 * * *', …)` of node-cron: file => expression.
     *
     * @return array<string, string>
     */
    public function schedules(): array
    {
        $schedules = [];

        foreach ($this->files as $file) {
            foreach ($this->facts->calls($file) as $call) {
                $expression = NodeFacts::text($call['arguments'][0] ?? null);

                if (null !== $expression && 1 === preg_match('/^([A-Za-z_$][\w$]*)\.schedule$/', $call['callee'], $receiver) && $this->fromPackage($file, $receiver[1], 'node-cron')) {
                    $schedules[$file] = $expression;
                }
            }
        }

        ksort($schedules, \SORT_STRING);

        return $schedules;
    }

    /**
     * Listeners of a project's emitter, `bus.on('order.created', …)`, and the files that emit the event.
     *
     * @return list<array{event: string, file: string, name: string, emitters: list<string>}>
     */
    public function listeners(): array
    {
        $emitted = [];

        foreach ($this->files as $file) {
            foreach ($this->facts->calls($file) as $call) {
                $event = NodeFacts::text($call['arguments'][0] ?? null);

                if (null !== $event && 1 === preg_match('/^([A-Za-z_$][\w$]*)\.emit$/', $call['callee'], $receiver) && $this->isProjectEmitter($file, $receiver[1])) {
                    $emitted[$event][] = $file;
                }
            }
        }

        $listeners = [];

        foreach ($this->files as $file) {
            foreach ($this->facts->calls($file) as $call) {
                $event = NodeFacts::text($call['arguments'][0] ?? null);

                if (null !== $event && 1 === preg_match('/^([A-Za-z_$][\w$]*)\.(on|once)$/', $call['callee'], $receiver) && $this->isProjectEmitter($file, $receiver[1])) {
                    $handler = NodeFacts::identifier($call['arguments'][1] ?? null);
                    $listeners[] = ['event' => $event, 'file' => $file, 'name' => $handler ?? pathinfo($file, \PATHINFO_FILENAME), 'emitters' => array_values(array_unique($emitted[$event] ?? []))];
                }
            }
        }

        return $listeners;
    }

    /**
     * The routers and applications a file creates: `express()`, `express.Router()`, `Router()`.
     */
    private function declare(string $file): void
    {
        [$express, $router] = $this->expressNames($file);

        foreach ($this->facts->variables($file) as $variable) {
            $call = \is_array($variable['value']) && \is_string($variable['value']['$call'] ?? null) ? $variable['value']['$call'] : null;

            if (null === $call) {
                continue;
            }

            if (\in_array($call, $express, true)) {
                $this->routers[$file.'#'.$variable['name']] = ['file' => $file, 'app' => true];
            } elseif (\in_array($call, $router, true) || \in_array($call, array_map(static fn (string $local): string => $local.'.Router', $express), true)) {
                $this->routers[$file.'#'.$variable['name']] = ['file' => $file, 'app' => false];
            }
        }
    }

    /**
     * `app.use('/orders', ordersRouter)`, `app.use('/admin', requireAuth, adminRouter)`, `router.use(auth)`.
     */
    private function mount(string $file): void
    {
        foreach ($this->facts->calls($file) as $call) {
            if (1 !== preg_match('/^([A-Za-z_$][\w$]*)\.use$/', $call['callee'], $receiver) || !isset($this->routers[$file.'#'.$receiver[1]])) {
                continue;
            }

            $parent = $file.'#'.$receiver[1];
            $arguments = $call['arguments'];
            $prefix = '';

            if (\is_string($arguments[0] ?? null)) {
                $prefix = $arguments[0];
                array_shift($arguments);
            }
            $middleware = [];
            $mounted = false;

            foreach ($arguments as $argument) {
                $child = $this->routerOf($file, $argument);

                if (null !== $child) {
                    $this->mounts[] = ['parent' => $parent, 'prefix' => $prefix, 'child' => $child, 'middleware' => $middleware];
                    $mounted = true;
                } elseif (null !== ($name = $this->nameOf($argument)) && !str_contains($name, '.json') && !str_contains($name, '.static') && !str_contains($name, '.urlencoded')) {
                    $middleware[] = $name;
                }
            }

            // `router.use(requireAuth)`: every route of that router is behind it.
            if (!$mounted && '' === $prefix && [] !== $middleware) {
                $this->routerMiddleware[$parent] = [...$this->routerMiddleware[$parent] ?? [], ...$middleware];
            }
        }
    }

    /**
     * @param array{callee: string, arguments: list<mixed>, class: string|null, line: int} $call
     *
     * @return array{string|null, string, string|null} the router, the verb, the literal path
     */
    private function routeCall(string $file, array $call): array
    {
        if (1 === preg_match('/^([A-Za-z_$][\w$]*)\.route\(([\'"`])([^\'"`]*)\2\)\.([a-z]+)$/', $call['callee'], $chained) && \in_array($chained[4], self::VERBS, true) && isset($this->routers[$file.'#'.$chained[1]])) {
            return [$file.'#'.$chained[1], $chained[4], $chained[3]];
        }

        if (1 !== preg_match('/^([A-Za-z_$][\w$]*)\.([a-z]+)$/', $call['callee'], $matches) || !\in_array($matches[2], self::VERBS, true) || !isset($this->routers[$file.'#'.$matches[1]]) || \count($call['arguments']) < 2) {
            return [null, '', null];
        }

        // `app.get('title')` reads a setting: a route has a path and a handler.
        return [$file.'#'.$matches[1], $matches[2], NodeFacts::text($call['arguments'][0])];
    }

    /**
     * The paths a router is reached at, with the middleware on the way and the files that mount it.
     *
     * @return list<array{string, list<string>, list<string>}>
     */
    private function prefixes(string $router, int $depth = 0): array
    {
        if (($this->routers[$router]['app'] ?? false) || $depth > 10) {
            return [['', [], [$this->routers[$router]['file'] ?? '']]];
        }

        $prefixes = [];

        foreach ($this->mounts as $mount) {
            if ($mount['child'] !== $router) {
                continue;
            }

            foreach ($this->prefixes($mount['parent'], $depth + 1) as [$prefix, $middleware, $chain]) {
                $prefixes[] = [self::join($prefix, $mount['prefix']), [...$middleware, ...$this->routerMiddleware[$mount['parent']] ?? [], ...$mount['middleware']], [...$chain, $this->routers[$router]['file'] ?? '']];
            }
        }

        if ([] === $prefixes) {
            $this->warnings[] = \sprintf('%s: a router no application mounts; its routes are documented from /.', explode('#', $router)[0]);

            return [['', [], [$this->routers[$router]['file'] ?? '']]];
        }

        return $prefixes;
    }

    /**
     * The router an argument of `use()` designates: a local router, one imported, or `require('./x')`.
     */
    private function routerOf(string $file, mixed $argument): ?string
    {
        if (null !== ($identifier = NodeFacts::identifier($argument))) {
            if (isset($this->routers[$file.'#'.$identifier])) {
                return $file.'#'.$identifier;
            }

            $origin = $this->facts->origin($file, $identifier);

            return null === $origin ? null : $this->exported($origin[0], $origin[1]);
        }

        if (\is_array($argument) && 'require' === ($argument['$call'] ?? null) && \is_array($argument['$arguments'] ?? null) && \is_string($argument['$arguments'][0] ?? null)) {
            foreach ($this->facts->imports($file) as $import) {
                if ($import['specifier'] === $argument['$arguments'][0] && null !== $import['resolved']) {
                    return $this->exported($import['resolved'], 'default');
                }
            }
        }

        return null;
    }

    /**
     * The router a file exports under a name — `default` being `export default` and `module.exports`.
     */
    private function exported(string $file, string $name): ?string
    {
        if (isset($this->routers[$file.'#'.$name])) {
            return $file.'#'.$name;
        }

        foreach ($this->facts->variables($file) as $variable) {
            if (('default' === $name && \in_array($variable['name'], ['default', 'module.exports'], true)) || $variable['name'] === $name) {
                // `module.exports = express.Router()`: the export is the router itself.
                if (isset($this->routers[$file.'#'.$variable['name']])) {
                    return $file.'#'.$variable['name'];
                }

                $local = NodeFacts::identifier($variable['value']);

                return null !== $local && isset($this->routers[$file.'#'.$local]) ? $file.'#'.$local : null;
            }
        }

        return null;
    }

    /**
     * Where a route's handler is written: `orderController.list` lives in the controller's file, an inline
     * function in the router's.
     */
    private function declaringFile(string $file, mixed $handler): string
    {
        $name = $this->nameOf($handler);

        if (null === $name) {
            return $file;
        }

        return $this->facts->origin($file, explode('.', $name)[0])[0] ?? $file;
    }

    /**
     * `requireAuth`, `auth.required`, `rateLimit(…)` — or null for an inline function.
     */
    private function nameOf(mixed $argument): ?string
    {
        if (null !== ($identifier = NodeFacts::identifier($argument))) {
            return $identifier;
        }

        if (\is_array($argument) && \is_string($argument['$expression'] ?? null) && 1 === preg_match('/^[A-Za-z_$][\w$.]*$/', $argument['$expression'])) {
            return $argument['$expression'];
        }

        return \is_array($argument) && \is_string($argument['$call'] ?? null) ? $argument['$call'] : null;
    }

    /**
     * The local names a file binds `express` (and its `Router`) to.
     *
     * @return array{list<string>, list<string>}
     */
    private function expressNames(string $file): array
    {
        [$express, $router] = [[], []];

        foreach ($this->facts->imports($file) as $import) {
            if ('express' !== $import['package']) {
                continue;
            }

            foreach ($import['names'] as $local => $imported) {
                if ('default' === $imported || '*' === $imported) {
                    $express[] = $local;
                } elseif ('Router' === $imported) {
                    $router[] = $local;
                }
            }
        }

        return [$express, $router];
    }

    private function fromPackage(string $file, string $local, string $package): bool
    {
        return array_any($this->facts->imports($file), static fn (array $import): bool => $package === $import['package'] && isset($import['names'][$local]));
    }

    /**
     * An emitter of the project: a name the file imports from one of its own files.
     */
    private function isProjectEmitter(string $file, string $local): bool
    {
        $origin = $this->facts->origin($file, $local);

        return null !== $origin && $origin[0] !== $file;
    }

    private static function join(string $prefix, string $path): string
    {
        return '/'.trim(trim($prefix, '/').'/'.trim($path, '/'), '/');
    }

    /**
     * `/orders/:id` → `orders.id`, `/` → `root`.
     */
    private static function slug(string $path): string
    {
        $segments = array_map(static fn (string $segment): string => ltrim($segment, ':'), array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment)));

        return [] === $segments ? 'root' : implode('.', $segments);
    }
}
