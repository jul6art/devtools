<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * What the code says through Symfony attributes, read from the syntax trees the graph already parsed.
 *
 * It is the whole source for what the console does not expose (scheduled tasks, components) and the
 * fallback for everything else when the console cannot answer — with a lower confidence, since routes
 * declared in YAML or services wired in configuration are invisible here.
 */
final class StaticSymfonyScanner
{
    // Class names of the analysed project, written as strings on purpose: DevTools does not depend on these
    // packages, and a ::class constant would make that look otherwise.
    private const array ROUTE = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];

    private const string COMMAND = 'Symfony\Component\Console\Attribute\AsCommand';

    private const string HANDLER = 'Symfony\Component\Messenger\Attribute\AsMessageHandler';

    private const string LISTENER = 'Symfony\Component\EventDispatcher\Attribute\AsEventListener';

    private const string IS_GRANTED = 'Symfony\Component\Security\Http\Attribute\IsGranted';

    private const array SCHEDULED = ['Symfony\Component\Scheduler\Attribute\AsCronTask', 'Symfony\Component\Scheduler\Attribute\AsPeriodicTask'];

    private const array COMPONENT = ['Symfony\UX\LiveComponent\Attribute\AsLiveComponent' => true, 'Symfony\UX\TwigComponent\Attribute\AsTwigComponent' => false];

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
     * @return list<array{name: string, path: string, methods: string, class: string, method: string}>
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            $prefix = $this->attributes($class->attrGroups, self::ROUTE)[0] ?? null;
            $pathPrefix = null === $prefix ? '' : (self::stringArgument($prefix, 0, 'path') ?? '');
            $namePrefix = null === $prefix ? '' : (self::stringArgument($prefix, -1, 'name') ?? '');

            foreach ($class->getMethods() as $method) {
                foreach ($this->attributes($method->attrGroups, self::ROUTE) as $route) {
                    $routeName = self::stringArgument($route, -1, 'name');

                    if (null === $routeName) {
                        $this->warnings[] = \sprintf('%s::%s() declares a route without a name; name it to document it.', $name, $method->name->toString());

                        continue;
                    }

                    $methods = self::stringsArgument($route, -1, 'methods');
                    $routes[] = [
                        'name' => $namePrefix.$routeName,
                        'path' => '' === $pathPrefix.(self::stringArgument($route, 0, 'path') ?? '') ? '/' : $pathPrefix.(self::stringArgument($route, 0, 'path') ?? ''),
                        'methods' => [] === $methods ? 'ANY' : implode('|', array_map(strtoupper(...), $methods)),
                        'class' => $name,
                        'method' => $method->name->toString(),
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * @return array<string, string> class => command name
     */
    public function commands(): array
    {
        $commands = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            $command = $this->attributes($class->attrGroups, [self::COMMAND])[0] ?? null;
            $commandName = null === $command ? null : self::stringArgument($command, 0, 'name');

            if (null !== $commandName) {
                $commands[$name] = $commandName;
            }
        }

        return $commands;
    }

    /**
     * @return array<string, string> class => handling method
     */
    public function handlers(): array
    {
        return $this->annotatedMethods([self::HANDLER]);
    }

    /**
     * @return array<string, list<array{event: string, method: string}>>
     */
    public function listeners(): array
    {
        $listeners = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            foreach ($this->attributes($class->attrGroups, [self::LISTENER]) as $attribute) {
                $event = self::stringArgument($attribute, 0, 'event');

                if (null !== $event) {
                    $listeners[$name][] = ['event' => $event, 'method' => self::stringArgument($attribute, -1, 'method') ?? '__invoke'];
                }
            }

            foreach ($class->getMethods() as $method) {
                foreach ($this->attributes($method->attrGroups, [self::LISTENER]) as $attribute) {
                    $event = self::stringArgument($attribute, 0, 'event') ?? $this->firstParameterType($method);

                    if (null !== $event) {
                        $listeners[$name][] = ['event' => $event, 'method' => $method->name->toString()];
                    }
                }
            }
        }

        return $listeners;
    }

    /**
     * @return array<string, string> class => schedule expression
     */
    public function scheduledTasks(): array
    {
        $tasks = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            foreach ($this->attributes($class->attrGroups, self::SCHEDULED) as $attribute) {
                $tasks[$name] = self::stringArgument($attribute, 0, 'expression') ?? self::stringArgument($attribute, 0, 'frequency') ?? 'scheduled';
            }
        }

        return $tasks;
    }

    /**
     * @return array<string, bool> class => whether it is a live component
     */
    public function components(): array
    {
        $components = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            foreach (self::COMPONENT as $attribute => $live) {
                if ([] !== $this->attributes($class->attrGroups, [$attribute])) {
                    $components[$name] = $live;
                }
            }
        }

        return $components;
    }

    /**
     * Roles required by #[IsGranted] on the class, then on the method, in that order.
     *
     * @return list<string>
     */
    public function grantedRoles(string $class, string $method): array
    {
        $node = $this->classes()[$class]['class'] ?? null;

        if (null === $node) {
            return [];
        }

        $roles = [];

        foreach ([$node->attrGroups, $node->getMethod($method)->attrGroups ?? []] as $groups) {
            foreach ($this->attributes($groups, [self::IS_GRANTED]) as $attribute) {
                $role = self::stringArgument($attribute, 0, 'attribute');

                if (null !== $role) {
                    $roles[] = $role;
                }
            }
        }

        return $roles;
    }

    /**
     * The class of a handler's first parameter: the message it handles.
     */
    public function messageOf(string $class, string $method): ?string
    {
        $node = $this->classes()[$class]['class'] ?? null;
        $handler = $node?->getMethod($method);

        return null === $handler ? null : $this->firstParameterType($handler);
    }

    /**
     * Names of the parameters typed with a workflow: `WorkflowInterface $orderStateMachine` → `orderStateMachine`.
     *
     * @return list<string>
     */
    public function workflowParameters(string $class, string $method): array
    {
        $node = $this->classes()[$class]['class'] ?? null;
        $names = [];

        foreach ([$node?->getMethod($method), $node?->getMethod('__construct')] as $candidate) {
            foreach (null === $candidate ? [] : $candidate->params as $parameter) {
                if ($parameter->type instanceof Name && str_starts_with($parameter->type->toString(), 'Symfony\Component\Workflow\\') && $parameter->var instanceof Variable && \is_string($parameter->var->name)) {
                    $names[] = $parameter->var->name;
                }
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * @param list<string> $attributes
     *
     * @return array<string, string>
     */
    private function annotatedMethods(array $attributes): array
    {
        $methods = [];

        foreach ($this->classes() as $name => ['class' => $class]) {
            if ([] !== $this->attributes($class->attrGroups, $attributes)) {
                $methods[$name] = '__invoke';
            }

            foreach ($class->getMethods() as $method) {
                if ([] !== $this->attributes($method->attrGroups, $attributes)) {
                    $methods[$name] = $method->name->toString();
                }
            }
        }

        return $methods;
    }

    private function firstParameterType(ClassMethod $method): ?string
    {
        $type = ($method->params[0] ?? null)?->type;

        return $type instanceof Name ? $type->toString() : null;
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
            $tree = $this->extractor->syntaxTree($this->root->absolute($path));

            if (\is_string($tree)) {
                $this->warnings[] = str_replace($this->root->absolute($path), $path, $tree);

                continue;
            }

            foreach (new NodeFinder()->findInstanceOf($tree, ClassLike::class) as $class) {
                if (null !== $class->namespacedName) {
                    $this->classes[$class->namespacedName->toString()] = ['file' => new FileRef($path, RoleGuesser::guess($path)), 'class' => $class];
                }
            }
        }

        return $this->classes;
    }

    /**
     * @param array<Node\AttributeGroup> $groups
     * @param list<string>               $names
     *
     * @return list<Attribute>
     */
    private function attributes(array $groups, array $names): array
    {
        $found = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (\in_array($attribute->name->toString(), $names, true)) {
                    $found[] = $attribute;
                }
            }
        }

        return $found;
    }

    /**
     * An argument by name, or by position among the unnamed ones (-1: by name only).
     */
    private static function argument(Attribute $attribute, int $position, string $name): ?Expr
    {
        $index = 0;

        foreach ($attribute->args as $argument) {
            if (null !== $argument->name) {
                if ($name === $argument->name->toString()) {
                    return $argument->value;
                }

                continue;
            }

            if ($index++ === $position) {
                return $argument->value;
            }
        }

        return null;
    }

    private static function stringArgument(Attribute $attribute, int $position, string $name): ?string
    {
        $value = self::argument($attribute, $position, $name);

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringsArgument(Attribute $attribute, int $position, string $name): array
    {
        $value = self::argument($attribute, $position, $name);

        if ($value instanceof String_) {
            return [$value->value];
        }

        if (!$value instanceof Array_) {
            return [];
        }

        $strings = [];

        foreach ($value->items as $item) {
            if ($item->value instanceof String_) {
                $strings[] = $item->value->value;
            }
        }

        return $strings;
    }
}
