<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\JavaScript;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Claude\ClaudeDrivenAdapter;
use Jul6Art\DevTools\Inspection\Graph\DependencyResolverInterface;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\RoleGuesser;
use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Graph\TypeScriptDependencyResolver;
use Jul6Art\DevTools\Inspection\Graph\TypeScriptTestLocator;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Node\NodeBridge;
use Jul6Art\DevTools\Inspection\Node\NodeFacts;
use Jul6Art\DevTools\Inspection\Node\NodeLock;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * What every JavaScript or TypeScript adapter does the same way (ADR-0031): one Node process over the files
 * of the stack, a graph and a test locator fed by what it read — and the Claude path when Node or the
 * project's TypeScript is missing.
 */
final readonly class JavaScriptSources
{
    public const array EXTENSIONS = ['ts', 'tsx', 'mts', 'cts', 'js', 'jsx', 'mjs', 'cjs'];

    /**
     * @param list<string> $files the project paths read
     * @param list<string> $tests among them, the tests
     */
    private function __construct(public NodeFacts $facts, public array $files, public array $tests)
    {
    }

    /**
     * Reads the sources and tests of the stack, plus `$extra` directories (relative to the stack root) that
     * hold what they import — `src/environments` beside `src/app`.
     *
     * @param list<string> $extra
     * @param list<string> $calls
     * @param list<string> $news
     */
    public static function read(NodeBridge $bridge, ProjectRoot $root, StackProfile $stack, array $extra, array $calls, array $news = []): self
    {
        $files = array_values(array_unique([
            ...SourceFiles::in($root, $stack, [...$stack->sourceDirs, ...$extra], self::EXTENSIONS),
            ...SourceFiles::in($root, $stack, $stack->testDirs, self::EXTENSIONS),
        ]));
        sort($files, \SORT_STRING);
        $tests = array_values(array_filter($files, static fn (string $path): bool => TypeScriptTestLocator::isTest($path) || self::inTests($stack, $path)));

        return new self($bridge->analyze($root, $stack, $files, NodeBridge::QUERIES, $calls, $news), $files, $tests);
    }

    public static function bridge(Config $config): NodeBridge
    {
        return new NodeBridge($config->nodeBinary, $config->nodeTimeout);
    }

    /**
     * The graph a builder asks for, given its depth.
     *
     * @return \Closure(int): DependencyResolverInterface
     */
    public function graph(ProjectRoot $root, StackProfile $stack): \Closure
    {
        $lock = NodeLock::in($root->absolute($stack->root));

        return fn (int $depth): DependencyResolverInterface => new TypeScriptDependencyResolver($this->facts, $lock, $depth, static fn (string $path): FileRole => RoleGuesser::guess($path));
    }

    public function testLocator(): TypeScriptTestLocator
    {
        return new TypeScriptTestLocator($this->facts, $this->tests);
    }

    /**
     * The stack left to Claude, with the reason the native reading could not happen at the top of the report.
     */
    public static function claudePath(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor, string $cause, int $nodeCalls): AdapterResult
    {
        $claude = new ClaudeDrivenAdapter()->extract($root, $stack, $config, $extractor);

        return new AdapterResult($claude->candidates, $claude->templateDirectories, $claude->warnings, $cause.' The stack is left to the Claude path.', $claude->workflows, $claude->uncovered, $claude->discovery, $nodeCalls);
    }

    /**
     * Whether a file is a source of the application rather than one of its tests.
     */
    public function isSource(string $path): bool
    {
        return !\in_array($path, $this->tests, true);
    }

    private static function inTests(StackProfile $stack, string $path): bool
    {
        return array_any($stack->testDirs, static fn (string $directory): bool => str_starts_with($path, rtrim($stack->projectPath($directory), '/').'/'));
    }
}
