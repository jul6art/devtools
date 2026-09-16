<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads, without executing it, which classes a PHP file uses, which templates it renders and which files it
 * requires.
 *
 * Only names actually used count, after resolution: an import nothing uses is not a dependency. With a
 * method scope — the method of a route, a handler — the class members every method shares (parents,
 * attributes, properties, constructor) are kept and the other methods are left out, so a list route does
 * not inherit the form of the creation route.
 */
final class PhpReferenceExtractor
{
    private const array RENDERING_METHODS = ['render', 'renderView', 'renderBlock', 'renderBlockView', 'stream'];

    private const array ROUTING_METHODS = ['redirectToRoute', 'generateUrl'];

    private const string TEMPLATE_ATTRIBUTE = 'Symfony\\Bridge\\Twig\\Attribute\\Template';

    private readonly Parser $parser;

    /**
     * @var array<string, list<Node>|string> parsed statements, or the parse error, by absolute path
     */
    private array $parsed = [];

    private int $parses = 0;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
    }

    /**
     * @param list<string> $methods empty for the whole file
     */
    public function extract(string $absolutePath, array $methods = []): PhpReferences
    {
        $statements = $this->statements($absolutePath);

        if (\is_string($statements)) {
            return new PhpReferences(warning: $statements);
        }

        $scope = [] === $methods ? $statements : $this->scope($statements, $methods);
        $finder = new NodeFinder();
        $classes = [];

        foreach ($finder->findInstanceOf($scope, FullyQualified::class) as $name) {
            $classes[] = $name->toString();
        }

        return new PhpReferences(
            self::sorted($classes),
            self::sorted($this->templates($finder, $scope)),
            null,
            self::sorted($this->literalArguments($finder, $scope, self::ROUTING_METHODS)),
            self::sorted($this->includes($finder, $scope, \dirname($absolutePath))),
        );
    }

    /**
     * Whether a file holds PHP: its extension, or a PHP shebang on an extensionless script (`bin/cleanup`).
     */
    public static function isPhpFile(string $absolutePath): bool
    {
        if (str_ends_with($absolutePath, '.php')) {
            return true;
        }

        if (str_contains(basename($absolutePath), '.') || !is_file($absolutePath)) {
            return false;
        }

        $firstLine = strtok((string) file_get_contents($absolutePath, length: 128), "\n");

        return \is_string($firstLine) && str_starts_with($firstLine, '#!') && str_contains($firstLine, 'php');
    }

    /**
     * How many times a file was actually parsed: a file shared by many workflows is parsed once.
     */
    public function parsedFiles(): int
    {
        return $this->parses;
    }

    /**
     * @return list<Node>|string
     */
    private function statements(string $absolutePath): array|string
    {
        if (isset($this->parsed[$absolutePath])) {
            return $this->parsed[$absolutePath];
        }

        ++$this->parses;

        try {
            $statements = $this->parser->parse((string) file_get_contents($absolutePath)) ?? [];
            $traverser = new NodeTraverser(new NameResolver());

            return $this->parsed[$absolutePath] = array_values($traverser->traverse($statements));
        } catch (Error $error) {
            return $this->parsed[$absolutePath] = \sprintf('%s cannot be parsed: %s', $absolutePath, $error->getMessage());
        }
    }

    /**
     * @param list<Node>   $statements
     * @param list<string> $methods
     *
     * @return list<Node>
     */
    private function scope(array $statements, array $methods): array
    {
        $class = new NodeFinder()->findFirstInstanceOf($statements, ClassLike::class);

        if (!$class instanceof ClassLike) {
            return $statements;
        }

        $scope = [...$class->attrGroups];

        if ($class instanceof Class_) {
            $scope = [...$scope, ...array_filter([$class->extends]), ...$class->implements];
        }

        foreach ($class->stmts as $member) {
            if (!$member instanceof ClassMethod || '__construct' === $member->name->toString() || \in_array($member->name->toString(), $methods, true)) {
                $scope[] = $member;
            }
        }

        return array_values($scope);
    }

    /**
     * @param list<Node> $scope
     *
     * @return list<string>
     */
    private function templates(NodeFinder $finder, array $scope): array
    {
        $templates = $this->literalArguments($finder, $scope, self::RENDERING_METHODS);

        foreach ($finder->findInstanceOf($scope, Attribute::class) as $attribute) {
            if (self::TEMPLATE_ATTRIBUTE === $attribute->name->toString()) {
                $templates[] = self::firstStringArgument($attribute->args);
            }
        }

        return array_values(array_filter($templates, \is_string(...)));
    }

    /**
     * The files required or included by a literal path — `'lib/db.php'` or `__DIR__.'/../lib/db.php'` — both
     * resolved from the including file's directory. A computed path is not a reference.
     *
     * @param list<Node> $scope
     *
     * @return list<string>
     */
    private function includes(NodeFinder $finder, array $scope, string $directory): array
    {
        $paths = [];

        foreach ($finder->findInstanceOf($scope, Include_::class) as $include) {
            $expression = $include->expr;
            $path = match (true) {
                $expression instanceof String_ => $expression->value,
                $expression instanceof Concat && $expression->left instanceof Dir && $expression->right instanceof String_ => $expression->right->value,
                default => null,
            };

            if (null !== $path && '' !== $path) {
                $paths[] = str_starts_with($path, '/') && !$expression instanceof Concat ? $path : $directory.'/'.ltrim($path, '/');
            }
        }

        return $paths;
    }

    /**
     * The literal first argument of every call to one of `$methods`.
     *
     * @param list<Node>   $scope
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private function literalArguments(NodeFinder $finder, array $scope, array $methods): array
    {
        $values = [];

        foreach ($finder->findInstanceOf($scope, MethodCall::class) as $call) {
            if ($call->name instanceof Identifier && \in_array($call->name->toString(), $methods, true)) {
                $values[] = self::firstStringArgument($call->args);
            }
        }

        return array_values(array_filter($values, \is_string(...)));
    }

    /**
     * The parsed statements of a file, names resolved, shared with every other reader of the same scan.
     *
     * @return list<Node>|string the statements, or why the file cannot be parsed
     */
    public function syntaxTree(string $absolutePath): array|string
    {
        return $this->statements($absolutePath);
    }

    /**
     * @param array<Node> $arguments
     */
    private static function firstStringArgument(array $arguments): ?string
    {
        $first = $arguments[0] ?? null;

        return $first instanceof Arg && $first->value instanceof String_ ? $first->value->value : null;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, \SORT_STRING);

        return $values;
    }
}
