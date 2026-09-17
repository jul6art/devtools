<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * Existing tests of a workflow: test files that use its entry point or a file of its first hop
 * (ADR-0006). Each test file is analysed once per scan, whatever the number of workflows.
 *
 * A test directory also holds fixtures, factories and base classes; only a file named as a test counts, or
 * the page would list a helper as a test of a workflow it never exercises.
 */
final class TestLocator
{
    /**
     * @var array<string, list<string>>|null test path => project files it references
     */
    private ?array $references = null;

    public function __construct(
        private readonly ProjectRoot $root,
        private readonly StackProfile $stack,
        private readonly ClassLocator $locator,
        private readonly PhpReferenceExtractor $extractor,
    ) {
    }

    /**
     * @param list<FileRef> $nearFiles the entry point's file and its first hop
     *
     * @return list<FileRef>
     */
    public function testsOf(array $nearFiles): array
    {
        $near = array_map(static fn (FileRef $file): string => $file->path, $nearFiles);
        $tests = [];

        foreach ($this->references() as $test => $referenced) {
            if ([] !== array_intersect($near, $referenced)) {
                $tests[] = new FileRef($test, FileRole::Test);
            }
        }

        return $tests;
    }

    /**
     * `OrderTest.php`, `OrderTestCase.php`, `order_test.php`, `OrderSpec.php` — not `GraphFixture.php`.
     */
    private static function isTest(string $path): bool
    {
        return 1 === preg_match('/(Test|TestCase|Spec)\.php$/', basename($path)) || 1 === preg_match('/(^|[_.-])test[_.-]|[_.-]test\.php$/i', basename($path));
    }

    /**
     * @return array<string, list<string>>
     */
    private function references(): array
    {
        if (null !== $this->references) {
            return $this->references;
        }

        $this->references = [];

        foreach (SourceFiles::in($this->root, $this->stack, $this->stack->testDirs, ['php']) as $test) {
            if (!self::isTest($test)) {
                continue;
            }

            $located = array_map($this->locator->locate(...), $this->extractor->extract($this->root->absolute($test))->classes);
            $this->references[$test] = array_values(array_map(static fn (FileRef $file): string => $file->path, array_filter($located, static fn (mixed $file): bool => $file instanceof FileRef)));
        }

        return $this->references;
    }
}
