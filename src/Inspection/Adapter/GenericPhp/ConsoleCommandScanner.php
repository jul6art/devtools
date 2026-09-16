<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\GenericPhp;

use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * The commands of a `symfony/console` application without a framework: classes carrying `#[AsCommand]`, or
 * extending `Command` and naming themselves literally (`setName()`, `parent::__construct()`, `$defaultName`).
 *
 * @internal
 */
final class ConsoleCommandScanner
{
    // Class names of the analysed project, written as strings on purpose: DevTools does not depend on them.
    private const string AS_COMMAND = 'Symfony\Component\Console\Attribute\AsCommand';

    private const string COMMAND = 'Symfony\Component\Console\Command\Command';

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

    /**
     * @return list<array{name: string, file: FileRef}> sorted by name
     */
    public function commands(): array
    {
        $commands = [];

        foreach (SourceFiles::in($this->root, $this->stack, $this->stack->sourceDirs, ['php']) as $path) {
            $tree = $this->extractor->syntaxTree($this->root->absolute($path));

            if (\is_string($tree)) {
                $this->warnings[] = str_replace($this->root->absolute($path), $path, $tree);

                continue;
            }

            foreach (new NodeFinder()->findInstanceOf($tree, Class_::class) as $class) {
                $isCommand = self::COMMAND === $class->extends?->toString();
                $name = self::attributeName($class) ?? ($isCommand ? self::literalName($class) : null);

                if (null !== $name) {
                    $commands[$name] = ['name' => $name, 'file' => new FileRef($path, RoleGuesser::guess($path))];
                } elseif ($isCommand && !$class->isAbstract()) {
                    $this->warnings[] = \sprintf('%s names its command at runtime; give it #[AsCommand] to document it.', $path);
                }
            }
        }

        ksort($commands, \SORT_STRING);

        return array_values($commands);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private static function attributeName(Class_ $class): ?string
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::AS_COMMAND !== $attribute->name->toString()) {
                    continue;
                }

                foreach ($attribute->args as $index => $argument) {
                    if ((null === $argument->name ? 0 === $index : 'name' === $argument->name->toString()) && $argument->value instanceof String_) {
                        return $argument->value->value;
                    }
                }
            }
        }

        return null;
    }

    private static function literalName(Class_ $class): ?string
    {
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($class->stmts, Property::class) as $property) {
            foreach ($property->props as $item) {
                if ($property->isStatic() && 'defaultName' === $item->name->toString() && $item->default instanceof String_) {
                    return $item->default->value;
                }
            }
        }

        $calls = [
            ...array_filter($finder->findInstanceOf($class->stmts, MethodCall::class), static fn (MethodCall $call): bool => $call->name instanceof Identifier && 'setName' === $call->name->toString()),
            ...array_filter($finder->findInstanceOf($class->stmts, StaticCall::class), static fn (StaticCall $call): bool => $call->class instanceof Name && 'parent' === $call->class->toLowerString() && $call->name instanceof Identifier && '__construct' === $call->name->toString()),
        ];

        foreach ($calls as $call) {
            $first = $call->args[0] ?? null;

            if ($first instanceof Arg && $first->value instanceof String_) {
                return $first->value->value;
            }
        }

        return null;
    }
}
