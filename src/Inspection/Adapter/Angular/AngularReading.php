<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Angular;

use Jul6Art\DevTools\Inspection\Node\NodeFacts;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * What the facts of an Angular application mean (ADR-0032): its routes, the components its screens share,
 * the services calling an API. The Node bridge read; this decides.
 *
 * @internal
 */
final class AngularReading
{
    private const array GUARDS = ['canActivate', 'canActivateChild', 'canMatch', 'canLoad'];

    private const array HTTP = ['get', 'post', 'put', 'patch', 'delete', 'request', 'head'];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(private readonly ProjectRoot $root, private readonly NodeFacts $facts)
    {
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Every route with a screen, its path the concatenation of its parents', its guards theirs too.
     *
     * @param list<string> $files
     *
     * @return list<array{name: string, path: string, component: string|null, declaredIn: string, guards: list<string>, structural: list<string>, literal: bool}>
     */
    public function routes(array $files): array
    {
        $routes = [];

        foreach ($this->rootTables($files) as $table) {
            $this->walk($table['routes'], '', [], $table['structural'], $table['file'], true, $routes, 0);
        }

        $unique = [];

        foreach ($routes as $route) {
            $unique[$route['name']] ??= $route;
        }

        ksort($unique, \SORT_STRING);

        return array_values($unique);
    }

    /**
     * The non-routed components at least two screens import: a component one screen uses is part of it.
     *
     * @param list<string> $screens the files of the routed components
     *
     * @return list<array{name: string, selector: string, class: string, file: string}>
     */
    public function sharedComponents(array $screens): array
    {
        $components = [];

        foreach ($this->facts->files() as $file) {
            if (\in_array($file, $screens, true)) {
                continue;
            }

            foreach ($this->facts->decorators($file) as $decorator) {
                if ('Component' !== $decorator['name'] || null === $decorator['target']) {
                    continue;
                }

                $users = array_filter($screens, fn (string $screen): bool => \in_array($file, array_column($this->facts->imports($screen), 'resolved'), true));

                if (\count($users) < 2) {
                    continue;
                }

                $options = $decorator['arguments'][0] ?? null;
                $selector = \is_array($options) ? NodeFacts::text($options['selector'] ?? null) : null;
                $components[] = [
                    'name' => null === $selector ? self::kebab((string) preg_replace('/Component$/', '', $decorator['target'])) : (string) preg_replace('/^[a-z0-9]+-(?=.)/', '', $selector),
                    'selector' => $selector ?? self::kebab($decorator['target']),
                    'class' => $decorator['target'],
                    'file' => $file,
                ];
            }
        }

        return $components;
    }

    /**
     * The services injecting `HttpClient`, and the endpoints they call: method and URL, the URL's constants
     * resolved — `${environment.apiUrl}/orders/${id}` is `/api/orders/:param`.
     *
     * @param list<string> $files
     *
     * @return list<array{name: string, class: string, file: string, endpoints: list<string>}>
     */
    public function httpServices(array $files): array
    {
        $services = [];

        foreach ($files as $file) {
            foreach ($this->facts->classes($file) as $class) {
                if (!\in_array('HttpClient', $class['injects'], true)) {
                    continue;
                }

                $clients = [];

                foreach ($class['properties'] as $property) {
                    $value = $property['value'];

                    $arguments = \is_array($value) && \is_array($value['$arguments'] ?? null) ? $value['$arguments'] : [];

                    if ('HttpClient' === $property['type'] || (\is_array($value) && 'inject' === ($value['$call'] ?? null) && 'HttpClient' === NodeFacts::identifier($arguments[0] ?? null))) {
                        $clients[] = $property['name'];
                    }
                }

                $endpoints = [];

                foreach ($this->facts->calls($file) as $call) {
                    if ($call['class'] !== $class['name'] || 1 !== preg_match('/^this\.([A-Za-z_$][\w$]*)\.([a-z]+)$/', $call['callee'], $matches) || !\in_array($matches[1], $clients, true) || !\in_array($matches[2], self::HTTP, true)) {
                        continue;
                    }

                    $url = $this->url($file, $call['arguments'][0] ?? null);
                    $endpoints[] = strtoupper($matches[2]).' '.$url;
                }

                $endpoints = array_values(array_unique($endpoints));
                sort($endpoints, \SORT_STRING);
                $services[] = ['name' => self::kebab($class['name']), 'class' => $class['name'], 'file' => $file, 'endpoints' => $endpoints];
            }
        }

        return $services;
    }

    /**
     * The links of a screen: the `routerLink`s of its template and the `router.navigate()` calls of its class,
     * as path segments — null for a segment computed at run time.
     *
     * @return list<array{list<string|null>, string}>
     */
    public function links(string $component): array
    {
        $links = [];
        $template = '';

        foreach ($this->facts->decorators($component) as $decorator) {
            $options = 'Component' === $decorator['name'] ? ($decorator['arguments'][0] ?? null) : null;

            if (!\is_array($options)) {
                continue;
            }

            $template .= self::templateText($options['template'] ?? null);
            $url = NodeFacts::text($options['templateUrl'] ?? null);
            $html = null === $url ? null : $this->root->absolute(\dirname($component).'/'.$url);

            if (null !== $html && is_file($html) && str_starts_with((string) realpath($html), $this->root->path.'/')) {
                $template .= (string) file_get_contents($html);
            }
        }

        preg_match_all('/(?<![\[\w-])routerLink\s*=\s*"([^"]*)"/', $template, $static);

        foreach ($static[1] as $link) {
            $links[] = [self::segments($link), 'link'];
        }

        preg_match_all('/\[routerLink\]\s*=\s*"([^"]*)"/', $template, $bound);

        foreach ($bound[1] as $expression) {
            $links[] = [self::expressionSegments($expression), 'link'];
        }

        foreach ($this->facts->calls($component) as $call) {
            if (1 !== preg_match('/\.(navigate|navigateByUrl)$/', $call['callee'])) {
                continue;
            }

            $target = $call['arguments'][0] ?? null;

            if (\is_string($target)) {
                $links[] = [self::segments($target), 'navigate'];
            } elseif (\is_array($target) && array_is_list($target)) {
                $segments = [];

                foreach ($target as $part) {
                    $segments = [...$segments, ...(\is_string($part) ? self::segments($part) : [null])];
                }

                $links[] = [$segments, 'navigate'];
            }
        }

        return $links;
    }

    /**
     * Whether a link reaches a route: `/orders/:id` is reached by `['/orders', order.id]` and by `/orders/12`.
     *
     * @param list<string|null> $segments
     */
    public static function matches(string $path, array $segments): bool
    {
        $route = self::segments($path);

        if (\count($route) !== \count($segments)) {
            return false;
        }

        foreach ($route as $index => $segment) {
            $link = $segments[$index];

            if (!str_starts_with((string) $segment, ':') && $segment !== $link) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed>                                                                                                                                        $routes
     * @param list<string>                                                                                                                                       $guards
     * @param list<string>                                                                                                                                       $structural
     * @param list<array{name: string, path: string, component: string|null, declaredIn: string, guards: list<string>, structural: list<string>, literal: bool}> $out
     */
    private function walk(array $routes, string $prefix, array $guards, array $structural, string $file, bool $literal, array &$out, int $depth): void
    {
        if ($depth > 10) {
            return;
        }

        foreach ($routes as $route) {
            if (!\is_array($route) || isset($route['redirectTo'])) {
                continue;
            }

            $path = \array_key_exists('path', $route) ? NodeFacts::text($route['path']) : '';

            if (null === $path) {
                $this->warnings[] = \sprintf('%s: a route under "/%s" has a path computed at run time; it is not documented.', $file, $prefix);

                continue;
            }

            $full = trim($prefix.'/'.$path, '/');
            $routeGuards = [...$guards, ...self::guards($route)];

            // A guard decides who reaches the route: its file is one of the route's.
            foreach (self::guards($route) as $guard) {
                $guardFile = $this->facts->origin($file, $guard)[0] ?? null;

                if (null !== $guardFile) {
                    $structural[] = $guardFile;
                }
            }

            $here = $literal;
            $component = null;

            if (null !== ($identifier = NodeFacts::identifier($route['component'] ?? null))) {
                $component = $this->facts->origin($file, $identifier)[0] ?? null;
            } elseif (isset($route['loadComponent'])) {
                $component = self::lazy($route['loadComponent'])[0] ?? null;

                if (null === $component) {
                    $here = false;
                    $this->warnings[] = \sprintf('%s: /%s loads its component from a path that is not literal; its workflow has a medium confidence.', $file, $full);
                }
            }

            $children = \is_array($route['children'] ?? null) ? array_values($route['children']) : [];

            if ([] !== $children) {
                // A component with children is their layout: a file of each of them, not a screen of its own.
                $this->walk($children, $full, $routeGuards, [...$structural, ...(null === $component ? [] : [$component])], $file, $here, $out, $depth + 1);

                continue;
            }

            if (isset($route['loadChildren'])) {
                [$childFile, $member] = self::lazy($route['loadChildren']) ?? [null, null];
                $table = null === $childFile ? null : $this->table($childFile, $member);

                if (null === $childFile || null === $table) {
                    $this->warnings[] = \sprintf('%s: the children of /%s are loaded from a path that is not literal; they are not documented.', $file, $full);

                    continue;
                }

                $this->walk($table, $full, $routeGuards, [...$structural, $childFile], $childFile, $here, $out, $depth + 1);

                continue;
            }

            if (null === $component && $here) {
                continue;
            }

            $out[] = [
                'name' => self::name($full),
                'path' => '/'.$full,
                'component' => $component,
                'declaredIn' => $file,
                'guards' => array_values(array_unique($routeGuards)),
                'structural' => array_values(array_unique([...$structural, $file])),
                'literal' => $here,
            ];
        }
    }

    /**
     * The tables the router is given: `provideRouter(routes)`, `RouterModule.forRoot(routes)` — or, with
     * neither, the exported `Routes` of `app.routes.ts` and `app-routing.module.ts`.
     *
     * @param list<string> $files
     *
     * @return list<array{file: string, routes: list<mixed>, structural: list<string>}>
     */
    private function rootTables(array $files): array
    {
        $tables = [];

        foreach ($files as $file) {
            foreach ($this->facts->calls($file) as $call) {
                if (1 !== preg_match('/(^|\.)provideRouter$|RouterModule\.forRoot$/', $call['callee'])) {
                    continue;
                }

                $argument = $call['arguments'][0] ?? null;

                if (\is_array($argument) && array_is_list($argument)) {
                    $tables[] = ['file' => $file, 'routes' => $argument, 'structural' => [$file]];
                } elseif (null !== ($identifier = NodeFacts::identifier($argument)) && null !== ($origin = $this->facts->origin($file, $identifier))) {
                    $routes = $this->table($origin[0], $origin[1]);

                    if (null !== $routes) {
                        $tables[] = ['file' => $origin[0], 'routes' => $routes, 'structural' => array_values(array_unique([$file, $origin[0]]))];
                    }
                }
            }
        }

        if ([] !== $tables) {
            return $tables;
        }

        foreach ($files as $file) {
            if (1 === preg_match('#(^|/)(app\.routes|app-routing\.module)\.ts$#', $file)) {
                foreach ($this->facts->variables($file) as $variable) {
                    if ($variable['exported'] && str_contains((string) $variable['type'], 'Routes') && \is_array($variable['value'])) {
                        $tables[] = ['file' => $file, 'routes' => array_values($variable['value']), 'structural' => [$file]];
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * The route table a file exports under a name — `default` for `export default [...]`, or the only
     * `Routes` it declares when the lazy import names none.
     *
     * @return list<mixed>|null
     */
    private function table(string $file, ?string $member): ?array
    {
        foreach ($this->facts->variables($file) as $variable) {
            $named = null === $member ? str_contains((string) $variable['type'], 'Routes') || 'default' === $variable['name'] : $member === $variable['name'];

            if ($named && \is_array($variable['value']) && array_is_list($variable['value'])) {
                return $variable['value'];
            }
        }

        return null;
    }

    /**
     * A literal URL, its constants resolved; a segment computed at run time is `:param`, a prefix nobody can
     * read is `*`.
     */
    private function url(string $file, mixed $literal): string
    {
        if (\is_string($literal)) {
            return $literal;
        }

        $parts = \is_array($literal) && \is_array($literal['$template'] ?? null) ? $literal['$template'] : [$literal];
        $url = '';

        foreach ($parts as $part) {
            $url .= match (true) {
                \is_string($part) => $part,
                \is_array($part) && \is_string($part['$expression'] ?? null) => $this->constant($file, $part['$expression']) ?? '*',
                null !== NodeFacts::identifier($part) => $this->constant($file, NodeFacts::identifier($part)) ?? ':param',
                default => '*',
            };
        }

        return (string) preg_replace('#(?<!:)//+#', '/', $url);
    }

    /**
     * `environment.apiUrl`: the string the imported `environment` holds under `apiUrl`, or null.
     */
    private function constant(string $file, string $expression): ?string
    {
        $path = explode('.', $expression);
        $origin = $this->facts->origin($file, array_shift($path));

        if (null === $origin) {
            return null;
        }

        foreach ($this->facts->variables($origin[0]) as $variable) {
            if ($variable['name'] !== $origin[1]) {
                continue;
            }

            $value = $variable['value'];

            foreach ($path as $key) {
                $value = \is_array($value) ? ($value[$key] ?? null) : null;
            }

            return \is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * `() => import('./x').then((m) => m.Y)` → [the resolved file, `Y`].
     *
     * @return array{string, string|null}|null
     */
    private static function lazy(mixed $function): ?array
    {
        $import = \is_array($function) && \is_array($function['$imports'] ?? null) ? ($function['$imports'][0] ?? null) : null;

        if (!\is_array($import) || !\is_string($import['resolved'] ?? null)) {
            return null;
        }

        return [$import['resolved'], \is_string($import['member'] ?? null) ? $import['member'] : null];
    }

    /**
     * @param array<mixed> $route
     *
     * @return list<string>
     */
    private static function guards(array $route): array
    {
        $guards = [];

        foreach (self::GUARDS as $key) {
            foreach (\is_array($route[$key] ?? null) ? $route[$key] : [] as $guard) {
                $name = NodeFacts::identifier($guard) ?? (\is_array($guard) && \is_string($guard['$call'] ?? null) ? $guard['$call'] : null);

                if (null !== $name) {
                    $guards[] = $name;
                }
            }
        }

        return $guards;
    }

    /**
     * `orders/:id` → `orders.id`, `` → `root`, `**` → `wildcard`.
     */
    private static function name(string $path): string
    {
        $segments = array_map(static fn (string $segment): string => match (true) {
            '**' === $segment => 'wildcard',
            str_starts_with($segment, ':') => substr($segment, 1),
            default => $segment,
        }, array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment)));

        return [] === $segments ? 'root' : implode('.', $segments);
    }

    /**
     * @return list<string|null>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim((string) strtok($path, '?#'), '/')), static fn (string $segment): bool => '' !== $segment));
    }

    /**
     * `['/orders', order.id]` → ['orders', null]; `'/orders'` → ['orders'].
     *
     * @return list<string|null>
     */
    private static function expressionSegments(string $expression): array
    {
        $expression = trim($expression);

        if (1 === preg_match('/^([\'"])(.*)\1$/', $expression, $quoted)) {
            return self::segments($quoted[2]);
        }

        if (!str_starts_with($expression, '[')) {
            return [null];
        }

        $segments = [];

        foreach (explode(',', trim($expression, '[]')) as $item) {
            $item = trim($item);
            $segments = [...$segments, ...(1 === preg_match('/^([\'"])(.*)\1$/', $item, $quoted) ? self::segments($quoted[2]) : [null])];
        }

        return $segments;
    }

    private static function templateText(mixed $template): string
    {
        if (\is_string($template)) {
            return $template;
        }

        $parts = \is_array($template) && \is_array($template['$template'] ?? null) ? $template['$template'] : [];

        return implode('', array_filter($parts, \is_string(...)));
    }

    private static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name));
    }
}
