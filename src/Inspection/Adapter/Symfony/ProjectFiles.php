<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Inspection\Graph\SourceFiles;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * The files of a stack the adapter points at by convention: configuration, test sources.
 *
 * @internal
 */
final class ProjectFiles
{
    /**
     * @var array<string, string>|null test path => content
     */
    private ?array $tests = null;

    public function __construct(private readonly ProjectRoot $root, private readonly StackProfile $stack)
    {
    }

    /**
     * @param list<string> $candidates relative to the stack root
     *
     * @return list<FileRef>
     */
    public function existing(array $candidates, FileRole $role): array
    {
        $files = [];

        foreach ($candidates as $candidate) {
            $path = $this->stack->projectPath($candidate);

            if (is_file($this->root->absolute($path))) {
                $files[] = new FileRef($path, $role);
            }
        }

        return $files;
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<FileRef>
     */
    public function in(string $directory, FileRole $role, array $extensions = ['yaml', 'yml', 'php', 'xml']): array
    {
        return array_map(static fn (string $path): FileRef => new FileRef($path, $role), SourceFiles::in($this->root, $this->stack, [$directory], $extensions));
    }

    /**
     * Configuration files under config/packages declaring a top-level or framework-level key.
     *
     * @return list<FileRef>
     */
    public function declaring(string $key): array
    {
        return array_values(array_filter(
            $this->in('config/packages', FileRole::Config, ['yaml', 'yml']),
            fn (FileRef $file): bool => 1 === preg_match('/^\s{0,4}'.preg_quote($key, '/').':/m', (string) file_get_contents($this->root->absolute($file))),
        ));
    }

    /**
     * Tests whose source contains the route's path as a literal, e.g. `request('GET', '/orders')`.
     * Paths with parameters are skipped: their literal form in a test is unknowable.
     *
     * @return list<FileRef>
     */
    public function testsRequesting(string $path): array
    {
        if (str_contains($path, '{')) {
            return [];
        }

        $this->tests ??= array_combine(
            $paths = SourceFiles::in($this->root, $this->stack, $this->stack->testDirs, ['php']),
            array_map(fn (string $test): string => (string) file_get_contents($this->root->absolute($test)), $paths),
        );

        $found = [];

        foreach ($this->tests as $test => $content) {
            if (str_contains($content, "'".$path."'") || str_contains($content, '"'.$path.'"')) {
                $found[] = new FileRef($test, FileRole::Test);
            }
        }

        return $found;
    }
}
