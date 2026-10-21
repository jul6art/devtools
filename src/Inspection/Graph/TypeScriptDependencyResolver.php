<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Node\NodeFacts;
use Jul6Art\DevTools\Inspection\Node\NodeLock;

/**
 * The files a JavaScript or TypeScript entry point traverses, breadth first up to a depth (ADR-0031) — the
 * contract of {@see DependencyResolver}, fed by the imports the Node bridge resolved with the project's own
 * `tsconfig.json`. An import of `node_modules` is a package, with the version the lock file holds.
 *
 * Angular's convention is followed too: the `templateUrl` and `styleUrl(s)` a decorator names are files of
 * the component.
 */
final readonly class TypeScriptDependencyResolver implements DependencyResolverInterface
{
    /**
     * @param \Closure(string): FileRole $role
     */
    public function __construct(
        private NodeFacts $facts,
        private NodeLock $lock,
        private int $maxDepth,
        private \Closure $role,
    ) {
    }

    #[\Override]
    public function resolve(FileRef $entryFile, array $methods = [], array $structural = []): ResolvedDependencies
    {
        $files = [$entryFile->path => $entryFile];
        $direct = $files;
        // A route table declares, it runs nothing: a route whose screen it cannot name (a lazy import of a
        // computed path) is not every screen the table imports.
        $level = FileRole::Config === $entryFile->role ? [] : [$entryFile];
        $packages = [];

        for ($depth = 0; $depth < $this->maxDepth && [] !== $level; ++$depth) {
            $nextLevel = [];

            foreach ($level as $file) {
                foreach ($this->neighbours($file) as $neighbour) {
                    if ($neighbour instanceof PackageRef) {
                        $packages[$neighbour->name] = $neighbour;
                    } elseif (!isset($files[$neighbour->path])) {
                        $files[$neighbour->path] = $neighbour;
                        $nextLevel[] = $neighbour;
                    }
                }
            }

            if (0 === $depth) {
                foreach ($nextLevel as $neighbour) {
                    $direct[$neighbour->path] = $neighbour;
                }
            }

            $level = $nextLevel;
        }

        foreach ($structural as $file) {
            $files[$file->path] ??= $file;
        }

        ksort($files, \SORT_STRING);
        ksort($direct, \SORT_STRING);
        ksort($packages, \SORT_STRING);

        return new ResolvedDependencies(array_values($files), array_values($direct), array_values($packages), []);
    }

    /**
     * @return list<FileRef|PackageRef>
     */
    private function neighbours(FileRef $file): array
    {
        $neighbours = [];

        foreach ($this->facts->imports($file->path) as $import) {
            if (null !== $import['resolved']) {
                $neighbours[] = new FileRef($import['resolved'], ($this->role)($import['resolved']));
            } elseif (null !== $import['package'] && null !== ($version = $this->lock->version($import['package']))) {
                $neighbours[] = new PackageRef($import['package'], $version);
            }
        }

        foreach ($this->facts->decorators($file->path) as $decorator) {
            foreach ($decorator['arguments'] as $argument) {
                if (!\is_array($argument)) {
                    continue;
                }

                $urls = [$argument['templateUrl'] ?? null, $argument['styleUrl'] ?? null, ...(\is_array($argument['styleUrls'] ?? null) ? $argument['styleUrls'] : [])];

                foreach (array_filter($urls, \is_string(...)) as $url) {
                    $path = self::join(\dirname($file->path), $url);

                    if (null !== $path) {
                        $neighbours[] = new FileRef($path, str_ends_with($path, '.html') ? FileRole::Template : FileRole::Other);
                    }
                }
            }
        }

        return $neighbours;
    }

    /**
     * `src/app/home` + `./home.component.html`; null for a path that would leave the project.
     */
    private static function join(string $directory, string $relative): ?string
    {
        $segments = [];

        foreach (explode('/', ('.' === $directory ? '' : $directory.'/').$relative) as $segment) {
            if ('..' === $segment) {
                if ([] === $segments) {
                    return null;
                }

                array_pop($segments);
            } elseif ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return [] === $segments ? null : implode('/', $segments);
    }
}
