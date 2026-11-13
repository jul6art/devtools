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

    /**
     * @var array<string, list<string>>|null route file => its lines
     */
    private ?array $routeFiles = null;

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
     * The route file in which the project declares this route itself: its name as a key, its controller in the
     * block (ADR-0052). A route a bundle declares and the project only imports (`resource:`) has none — its name
     * is not a key of the project's files.
     *
     * Read like {@see declaring()}, without a YAML parser: a key at the top level or under `when@<env>:`, then the
     * lines indented below it. A localized route is named `<key>.<locale>` by Symfony: its key is the name without
     * the locale.
     */
    public function declaringRoute(string $name, string $class): ?FileRef
    {
        $names = 1 === preg_match('/^(.+)\.[a-z]{2,3}(?:_[A-Za-z]{2,4})?$/', $name, $localized) ? [$name, $localized[1]] : [$name];

        if (null === $this->routeFiles) {
            $this->routeFiles = [];

            foreach ([...$this->existing(['config/routes.yaml', 'config/routes.yml'], FileRole::Config), ...$this->in('config/routes', FileRole::Config, ['yaml', 'yml'])] as $file) {
                $this->routeFiles[$file->path] = preg_split('/\R/', (string) file_get_contents($this->root->absolute($file))) ?: [];
            }
        }

        foreach ($names as $key) {
            foreach ($this->routeFiles as $path => $lines) {
                if (self::declaresRoute($lines, $key, ltrim($class, '\\'))) {
                    return new FileRef($path, FileRole::Config);
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private static function declaresRoute(array $lines, string $name, string $class): bool
    {
        foreach ($lines as $index => $line) {
            if (1 !== preg_match('/^( {0,4})[\'"]?'.preg_quote($name, '/').'[\'"]?:\s*(#.*)?$/', $line, $key)) {
                continue;
            }

            foreach (\array_slice($lines, $index + 1) as $next) {
                if ('' === trim($next) || str_starts_with(ltrim($next), '#')) {
                    continue;
                }

                if (\strlen($next) - \strlen(ltrim($next)) <= \strlen($key[1])) {
                    break;
                }

                // `controller: Vendor\Controller::method`, quoted or not, its backslashes doubled or not.
                if (1 === preg_match('/^\s+_?controller:\s*[\'"]?([^\'"\s#]+)/', $next, $controller)
                    && $class === explode('::', ltrim(str_replace('\\\\', '\\', $controller[1]), '\\'), 2)[0]) {
                    return true;
                }
            }
        }

        return false;
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
