<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Project;

use Jul6Art\DevTools\Inspection\Model\FileRef;

/**
 * The analysed project on disk, and the last guard before any file is read or written.
 *
 * A FileRef already refuses what is lexically outside the project; this also resolves symbolic
 * links, because a link committed in an analysed repository could otherwise point anywhere on the
 * machine (.github/SECURITY.md).
 */
final readonly class ProjectRoot
{
    public string $path;

    public function __construct(string $path)
    {
        $resolved = realpath($path);

        if (false === $resolved || !is_dir($resolved)) {
            throw new \InvalidArgumentException(\sprintf('The project root "%s" is not an existing directory.', $path));
        }

        $this->path = rtrim($resolved, '/');
    }

    public function absolute(FileRef|string $relative): string
    {
        $given = $relative instanceof FileRef ? $relative->path : str_replace('\\', '/', $relative);
        $candidate = str_starts_with($given, '/') ? $given : $this->path.'/'.$given;
        $lexical = self::collapse($candidate) ?? throw PathOutsideProject::for($given, $this->path);

        if (!$this->contains($lexical) || !$this->contains(self::resolveExistingPart($lexical))) {
            throw PathOutsideProject::for($given, $this->path);
        }

        return $lexical;
    }

    public function relative(string $absolute): string
    {
        $inside = $this->absolute($absolute);

        return $inside === $this->path ? '' : substr($inside, \strlen($this->path) + 1);
    }

    private function contains(string $absolute): bool
    {
        return $absolute === $this->path || str_starts_with($absolute, $this->path.'/');
    }

    /**
     * Removes `.` and `..` without touching the disk; null when `..` climbs above the filesystem root.
     */
    private static function collapse(string $absolute): ?string
    {
        $segments = [];

        foreach (explode('/', $absolute) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $segments) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * Follows symbolic links through the part of the path that exists, then re-appends the rest: a file
     * about to be created inside a linked directory is checked against where the link really leads.
     */
    private static function resolveExistingPart(string $absolute): string
    {
        $missing = [];
        $current = $absolute;

        while (false === ($resolved = realpath($current))) {
            $missing[] = basename($current);
            $parent = \dirname($current);

            if ($parent === $current) {
                return $absolute;
            }

            $current = $parent;
        }

        return rtrim($resolved.'/'.implode('/', array_reverse($missing)), '/');
    }
}
