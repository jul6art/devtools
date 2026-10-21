<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Inspection\Graph\AttributeArgument;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * What the code of a Laravel application says, read from the syntax trees the graph already parsed
 * (ADR-0030).
 *
 * It is the whole source for what Artisan does not expose — jobs, the schedule, components — and the
 * fallback for routes, commands and listeners when Artisan cannot answer, with a lower confidence: a route
 * registered by a package or a macro is invisible here.
 */
final class StaticLaravelScanner
{
    // Class names of the analysed project, written as strings on purpose: DevTools does not depend on Laravel.
    private const string ROUTE = 'Illuminate\Support\Facades\Route';

    private const string SCHEDULE = 'Illuminate\Support\Facades\Schedule';

    private const string ARTISAN = 'Illuminate\Support\Facades\Artisan';

    private const string COMMAND = 'Illuminate\Console\Command';

    private const string AS_COMMAND = 'Symfony\Component\Console\Attribute\AsCommand';

    private const string SHOULD_QUEUE = 'Illuminate\Contracts\Queue\ShouldQueue';

    private const array COMPONENTS = ['Livewire\Component' => 'livewire', 'Illuminate\View\Component' => 'blade'];

    private const array VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    /**
     * The routes file Laravel prefixes: `routes/api.php` is served under `/api`.
     */
    private const array PREFIXED_FILES = ['routes/api.php' => 'api'];

    /**
     * @var array<string, array{file: FileRef, class: ClassLike}>|null
     */
    private ?array $classes = null;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly ProjectRoot $root,
        private readonly StackProfile $stack,
        private readonly PhpReferenceExtractor $extractor,
    ) {
    }

    public function fileOf(string $class): ?FileRef
    {
        return $this->classes()[ltrim($class, '\\')]['file'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * The routes the files of `routes/` declare, groups followed: their prefix, name and middleware.
     *
     * @return list<array{name: string|null, methods: string, uri: string, action: string, middleware: list<string>, file: string}>
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->routeFiles() as $path) {
            $tree = $this->tree($path);
            $prefix = self::PREFIXED_FILES['.' === $this->stack->root ? $path : substr($path, \strlen($this->stack->root) + 1)] ?? '';

            foreach ($tree as $statement) {
                $routes = [...$routes, ...$this->routesIn($statement, ['prefix' => $prefix, 'name' => '', 'middleware' => []], $path)];
            }
        }

        return $routes;
    }

    /**
     * Command name => class, from the commands of the project and the closures of `routes/console.php`
     * (the class is then null).
     *
     * @return array<string, array{class: string|null, file: FileRef}>
     */
    public function commands(): array
    {
        $commands = [];

        foreach ($this->classes() as $name => ['file' => $file, 'class' => $class]) {
            if (!$class instanceof Class_ || !$this->extends($class, self::COMMAND)) {
                continue;
            }

            $command = $this->commandName($class);

            if (null === $command) {
                $this->warnings[] = \sprintf('%s extends Command without a literal $signature or $name; give it one to document it.', $name);

                continue;
            }

            $commands[$command] = ['class' => $name, 'file' => $file];
        }

        foreach ($this->consoleFiles() as $path) {
            foreach (new NodeFinder()->findInstanceOf($this->tree($path), StaticCall::class) as $call) {
                if (self::isFacadeCall($call, self::ARTISAN, 'command') && null !== ($name = self::firstString($call->args))) {
                    $commands[strtok($name, ' ') ?: $name] ??= ['class' => null, 'file' => new FileRef($path, RoleGuesser::guess($path))];
                }
            }
        }

        ksort($commands, \SORT_STRING);

        return $commands;
    }

    /**
     * Queued jobs: classes implementing ShouldQueue with a `handle()` method — a queued listener is a
     * listener, not a job.
     *
     * @param list<string> $listeners classes known as listeners
     *
     * @return list<string>
     */
    public function jobs(array $listeners): array
    {
        $jobs = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            if ($class instanceof Class_ && $this->implements($class, self::SHOULD_QUEUE) && $class->getMethod('handle') instanceof ClassMethod && !\in_array($name, $listeners, true)) {
                $jobs[] = $name;
            }
        }

        sort($jobs, \SORT_STRING);

        return $jobs;
    }

    /**
     * Listeners Laravel discovers: a `handle` (or `__invoke`) method whose first parameter is the event,
     * in the classes of `app/Listeners`.
     *
     * @return array<string, list<string>> event => `Listener@method`
     */
    public function listeners(): array
    {
        $listeners = [];

        foreach ($this->classes() as $name => ['file' => $file, 'class' => $class]) {
            if (!$class instanceof Class_ || !str_contains('/'.$file->path, '/Listeners/')) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                $type = $method->params[0]->type ?? null;

                if (\in_array($method->name->toString(), ['handle', '__invoke'], true) && $type instanceof Name) {
                    $listeners[$type->toString()][] = $name.'@'.$method->name->toString();
                }
            }
        }

        ksort($listeners, \SORT_STRING);

        return $listeners;
    }

    /**
     * What the schedule runs: `Schedule::command('orders:import')->daily()` in `routes/console.php`, or
     * `$schedule->job(new SendReport)->hourly()` in a console kernel — and how often.
     *
     * @return list<array{kind: 'command'|'job', target: string, frequency: string, file: FileRef}>
     */
    public function schedule(): array
    {
        $tasks = [];

        foreach ([...$this->consoleFiles(), ...$this->existing(['app/Console/Kernel.php'])] as $path) {
            foreach (new NodeFinder()->findInstanceOf($this->tree($path), Expression::class) as $statement) {
                [$root, $chain] = self::chain($statement->expr);
                $kind = match (true) {
                    $root instanceof StaticCall && self::isFacadeCall($root, self::SCHEDULE, 'command'), $root instanceof MethodCall && 'command' === self::name($root) => 'command',
                    $root instanceof StaticCall && self::isFacadeCall($root, self::SCHEDULE, 'job'), $root instanceof MethodCall && 'job' === self::name($root) => 'job',
                    default => null,
                };

                if (null === $kind || !$root instanceof StaticCall && !$root instanceof MethodCall) {
                    continue;
                }

                $target = 'command' === $kind ? self::firstString($root->args) : self::firstClass($root->args);

                if (null !== $target) {
                    $tasks[] = [
                        'kind' => $kind,
                        'target' => 'command' === $kind ? (strtok($target, ' ') ?: $target) : $target,
                        'frequency' => self::frequency($chain),
                        'file' => new FileRef($path, RoleGuesser::guess($path)),
                    ];
                }
            }
        }

        return $tasks;
    }

    /**
     * Class => 'livewire' or 'blade'.
     *
     * @return array<string, 'livewire'|'blade'>
     */
    public function components(): array
    {
        $components = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            if ($class instanceof Class_ && !$class->isAbstract()) {
                foreach (self::COMPONENTS as $parent => $kind) {
                    if ($this->extends($class, $parent)) {
                        $components[$name] = $kind;
                    }
                }
            }
        }

        ksort($components, \SORT_STRING);

        return $components;
    }

    /**
     * The policy of a model, by Laravel's convention: `App\Models\Order` → `app/Policies/OrderPolicy.php`.
     */
    public function policyOf(string $model): ?FileRef
    {
        $short = ltrim(strrchr('\\'.$model, '\\') ?: $model, '\\');

        $path = $this->existing(['app/Policies/'.$short.'Policy.php'])[0] ?? null;

        return null === $path ? null : new FileRef($path, RoleGuesser::guess($path));
    }

    /**
     * @param list<string> $candidates relative to the stack root
     *
     * @return list<string> project paths
     */
    private function existing(array $candidates): array
    {
        return array_values(array_filter(
            array_map($this->stack->projectPath(...), $candidates),
            fn (string $path): bool => is_file($this->root->absolute($path)),
        ));
    }

    /**
     * @return list<string>
     */
    private function routeFiles(): array
    {
        return array_values(array_filter(SourceFiles::in($this->root, $this->stack, ['routes'], ['php']), static fn (string $path): bool => !\in_array(basename($path), ['console.php', 'channels.php'], true)));
    }

    /**
     * @return list<string>
     */
    private function consoleFiles(): array
    {
        return $this->existing(['routes/console.php']);
    }

    /**
     * @param array{prefix: string, name: string, middleware: list<string>} $group
     *
     * @return list<array{name: string|null, methods: string, uri: string, action: string, middleware: list<string>, file: string}>
     */
    private function routesIn(Node $statement, array $group, string $file): array
    {
        if (!$statement instanceof Expression) {
            return [];
        }

        [$root, $chain] = self::chain($statement->expr);

        if (!$root instanceof StaticCall || !$root->class instanceof Name || self::ROUTE !== $root->class->toString()) {
            return [];
        }

        $calls = [$root, ...$chain];
        $group = $this->narrowed($group, $calls);
        $closure = null;

        foreach ($calls as $call) {
            if ('group' === self::name($call)) {
                $closure = $call->args[0] ?? null;
            }
        }

        if ($closure instanceof Arg && ($closure->value instanceof Closure || $closure->value instanceof ArrowFunction)) {
            $routes = [];

            foreach ($closure->value instanceof Closure ? $closure->value->stmts : [] as $inner) {
                $routes = [...$routes, ...$this->routesIn($inner, $group, $file)];
            }

            return $routes;
        }

        $verb = self::name($root);

        if (!\in_array($verb, [...self::VERBS, 'match'], true)) {
            return [];
        }

        $arguments = 'match' === $verb ? \array_slice($root->args, 1) : $root->args;
        $methods = 'match' === $verb ? self::strings($root->args[0] ?? null) : ('any' === $verb ? ['ANY'] : [strtoupper($verb)]);
        $uri = self::firstString($arguments);

        if (null === $uri || [] === $methods) {
            return [];
        }

        $name = null;

        foreach ($chain as $call) {
            if ('name' === self::name($call)) {
                $name = self::firstString($call->args);
            }
        }

        return [[
            'name' => null === $name ? null : $group['name'].$name,
            'methods' => implode('|', array_map(strtoupper(...), $methods)),
            'uri' => trim(trim($group['prefix'], '/').'/'.trim($uri, '/'), '/'),
            'action' => $this->action($arguments[1] ?? null),
            'middleware' => $group['middleware'],
            'file' => $file,
        ]];
    }

    /**
     * The group a chain of calls opens: `Route::prefix('admin')->name('admin.')->middleware('auth')`.
     *
     * @param array{prefix: string, name: string, middleware: list<string>} $group
     * @param list<Expr>                                                    $calls
     *
     * @return array{prefix: string, name: string, middleware: list<string>}
     */
    private function narrowed(array $group, array $calls): array
    {
        foreach ($calls as $call) {
            $arguments = $call instanceof StaticCall || $call instanceof MethodCall ? $call->args : [];

            match (self::name($call)) {
                'prefix' => $group['prefix'] = trim($group['prefix'], '/').'/'.trim(self::firstString($arguments) ?? '', '/'),
                'name', 'as' => $group['name'] .= \in_array('group', array_map(self::name(...), $calls), true) ? (self::firstString($arguments) ?? '') : '',
                'middleware' => $group['middleware'] = [...$group['middleware'], ...self::strings($arguments[0] ?? null)],
                default => null,
            };
        }

        return $group;
    }

    /**
     * `[OrderController::class, 'show']` → `App\…\OrderController@show`; an invokable class → its name; a
     * closure → `Closure`.
     */
    private function action(?Node $argument): string
    {
        $value = $argument instanceof Arg ? $argument->value : null;

        return match (true) {
            $value instanceof Array_ && 2 === \count($value->items) && $value->items[0]?->value instanceof ClassConstFetch && $value->items[1]?->value instanceof String_ => self::className($value->items[0]->value).'@'.$value->items[1]->value->value,
            $value instanceof ClassConstFetch => self::className($value),
            $value instanceof String_ => $value->value,
            default => 'Closure',
        };
    }

    private function commandName(Class_ $class): ?string
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::AS_COMMAND === $attribute->name->toString()) {
                    $name = AttributeArgument::of($attribute, 0, 'name');

                    return $name instanceof String_ ? $name->value : null;
                }
            }
        }

        foreach ($class->stmts as $member) {
            if (!$member instanceof Property) {
                continue;
            }

            foreach ($member->props as $property) {
                if (\in_array($property->name->toString(), ['signature', 'name'], true) && $property->default instanceof String_) {
                    $signature = trim($property->default->value);

                    return '' === $signature ? null : (preg_split('/[\s{]/', $signature)[0] ?? $signature);
                }
            }
        }

        return null;
    }

    private function extends(Class_ $class, string $parent): bool
    {
        $seen = [];

        while ($class->extends instanceof Name) {
            $name = $class->extends->toString();

            if ($name === $parent) {
                return true;
            }

            $next = $this->classes()[$name]['class'] ?? null;

            if (!$next instanceof Class_ || isset($seen[$name])) {
                return false;
            }

            $seen[$name] = true;
            $class = $next;
        }

        return false;
    }

    private function implements(Class_ $class, string $interface): bool
    {
        foreach ($class->implements as $implemented) {
            if ($implemented->toString() === $interface) {
                return true;
            }
        }

        $parent = $class->extends instanceof Name ? ($this->classes()[$class->extends->toString()]['class'] ?? null) : null;

        return $parent instanceof Class_ && $parent !== $class && $this->implements($parent, $interface);
    }

    /**
     * @return list<Node>
     */
    private function tree(string $path): array
    {
        $tree = $this->extractor->syntaxTree($this->root->absolute($path));

        if (\is_string($tree)) {
            $this->warnings[] = str_replace($this->root->absolute($path), $path, $tree);

            return [];
        }

        return $tree;
    }

    /**
     * @return array<string, array{file: FileRef, class: ClassLike}>
     */
    private function classes(): array
    {
        if (null !== $this->classes) {
            return $this->classes;
        }

        $this->classes = [];

        foreach (SourceFiles::in($this->root, $this->stack, $this->stack->sourceDirs, ['php']) as $path) {
            if (str_ends_with($path, '.blade.php')) {
                continue;
            }

            foreach (new NodeFinder()->findInstanceOf($this->tree($path), ClassLike::class) as $class) {
                if (null !== $class->namespacedName) {
                    $this->classes[$class->namespacedName->toString()] = ['file' => new FileRef($path, RoleGuesser::guess($path)), 'class' => $class];
                }
            }
        }

        return $this->classes;
    }

    /**
     * A fluent chain read from its root: `Route::get(…)->name(…)->middleware(…)` gives the static call
     * and the method calls after it, in the order they are written.
     *
     * @return array{Expr, list<MethodCall>}
     */
    private static function chain(Expr $expression): array
    {
        $chain = [];

        while ($expression instanceof MethodCall && ($expression->var instanceof MethodCall || $expression->var instanceof StaticCall)) {
            array_unshift($chain, $expression);
            $expression = $expression->var;
        }

        return [$expression, $chain];
    }

    /**
     * @param list<MethodCall> $chain
     */
    private static function frequency(array $chain): string
    {
        foreach ($chain as $call) {
            $name = self::name($call);

            if ('cron' === $name) {
                return self::firstString($call->args) ?? 'cron';
            }

            if (null !== $name && 1 === preg_match('/^(every|daily|hourly|weekly|monthly|quarterly|yearly|twiceDaily|weekdays|weekends|mondays|tuesdays|wednesdays|thursdays|fridays|saturdays|sundays)/', $name)) {
                return $name;
            }
        }

        return 'scheduled';
    }

    private static function isFacadeCall(StaticCall $call, string $facade, string $method): bool
    {
        return $call->class instanceof Name && $facade === $call->class->toString() && $method === self::name($call);
    }

    private static function name(Expr $call): ?string
    {
        return ($call instanceof MethodCall || $call instanceof StaticCall) && $call->name instanceof Identifier ? $call->name->toString() : null;
    }

    private static function className(ClassConstFetch $fetch): string
    {
        return $fetch->class instanceof Name ? $fetch->class->toString() : '';
    }

    /**
     * @param array<Node> $arguments
     */
    private static function firstString(array $arguments): ?string
    {
        $first = $arguments[0] ?? null;

        return $first instanceof Arg && $first->value instanceof String_ ? $first->value->value : null;
    }

    /**
     * `new SendReport` or `SendReport::class`.
     *
     * @param array<Node> $arguments
     */
    private static function firstClass(array $arguments): ?string
    {
        $value = ($arguments[0] ?? null) instanceof Arg ? $arguments[0]->value : null;

        return match (true) {
            $value instanceof New_ && $value->class instanceof Name => $value->class->toString(),
            $value instanceof ClassConstFetch => self::className($value),
            default => null,
        };
    }

    /**
     * `'auth'` or `['auth', 'verified']` — and `'can:create,'.Order::class`, which a route file writes as
     * often as the literal.
     *
     * @return list<string>
     */
    private static function strings(?Node $argument): array
    {
        $value = $argument instanceof Arg ? $argument->value : null;

        if ($value instanceof Array_) {
            return array_values(array_filter(array_map(static fn (?ArrayItem $item): ?string => $item instanceof ArrayItem ? self::literal($item->value) : null, $value->items), \is_string(...)));
        }

        $literal = $value instanceof Expr ? self::literal($value) : null;

        return null === $literal ? [] : [$literal];
    }

    private static function literal(Expr $value): ?string
    {
        if ($value instanceof Concat) {
            $left = self::literal($value->left);
            $right = self::literal($value->right);

            return null === $left || null === $right ? null : $left.$right;
        }

        return match (true) {
            $value instanceof String_ => $value->value,
            $value instanceof ClassConstFetch && $value->name instanceof Identifier && 'class' === $value->name->toLowerString() => self::className($value),
            default => null,
        };
    }
}
