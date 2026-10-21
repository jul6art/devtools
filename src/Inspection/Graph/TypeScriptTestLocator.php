<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Node\NodeFacts;

/**
 * The tests of a JavaScript or TypeScript workflow (ADR-0031): the spec and test files — `*.spec.ts`,
 * `*.test.js`, the files of the test directories — that import a file of its first hop.
 */
final readonly class TypeScriptTestLocator implements TestLocatorInterface
{
    /**
     * @param list<string> $tests the project paths of the test files the bridge read
     */
    public function __construct(private NodeFacts $facts, private array $tests)
    {
    }

    public static function isTest(string $path): bool
    {
        return 1 === preg_match('/\.(spec|test)\.[cm]?[jt]sx?$/', $path);
    }

    #[\Override]
    public function testsOf(array $nearFiles): array
    {
        $near = array_map(static fn (FileRef $file): string => $file->path, $nearFiles);
        $found = [];

        foreach ($this->tests as $test) {
            $imported = array_filter(array_map(static fn (array $import): ?string => $import['resolved'], $this->facts->imports($test)));

            if ([] !== array_intersect($near, $imported)) {
                $found[] = new FileRef($test, FileRole::Test);
            }
        }

        usort($found, static fn (FileRef $a, FileRef $b): int => $a->path <=> $b->path);

        return $found;
    }
}
