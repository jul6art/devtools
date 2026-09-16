<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * A file of the analysed project, by its path relative to the project root.
 *
 * This is the only way a path enters the model, which makes it the guard for two promises: no page
 * or tracking file ever points outside the project, and no path reaches a writer that could escape
 * `.devtools/`. Dependencies are never FileRefs but PackageRefs (ADR-0003).
 */
final readonly class FileRef
{
    private const array DEPENDENCY_DIRECTORIES = ['vendor', 'node_modules'];

    public string $path;

    public function __construct(string $path, public FileRole $role = FileRole::Other)
    {
        $this->path = self::normalise($path);
    }

    public function samePathAs(self $other): bool
    {
        return $this->path === $other->path;
    }

    private static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_starts_with($path, '/') || 1 === preg_match('#^[A-Za-z]:/#', $path)) {
            throw new InvalidModel(\sprintf('The path "%s" is absolute; a file of the model is relative to the project root.', $path));
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => '' !== $segment && '.' !== $segment,
        ));

        if ([] === $segments) {
            throw new InvalidModel('A file of the model cannot have an empty path.');
        }

        if (\in_array('..', $segments, true)) {
            throw new InvalidModel(\sprintf('The path "%s" leads outside the project root.', $path));
        }

        if ([] !== array_intersect($segments, self::DEPENDENCY_DIRECTORIES)) {
            throw new InvalidModel(\sprintf('The path "%s" is inside a dependency directory; a dependency is a PackageRef, not a file of the project.', $path));
        }

        return implode('/', $segments);
    }
}
