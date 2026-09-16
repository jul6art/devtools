<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * Lists the files of some directories of a stack, skipping its excluded directories.
 *
 * @internal
 */
final class SourceFiles
{
    /**
     * @param list<string> $directories relative to the stack root
     * @param list<string> $extensions  empty for every file
     *
     * @return list<string> relative to the project root, sorted
     */
    public static function in(ProjectRoot $root, StackProfile $stack, array $directories, array $extensions): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $absolute = $root->absolute($stack->projectPath($directory));

            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !$file->isDir() || !\in_array($file->getFilename(), $stack->excludedDirs, true),
            ));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && self::hasExtension($file->getFilename(), $extensions)) {
                    $files[] = $root->relative($file->getPathname());
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $extensions
     */
    private static function hasExtension(string $name, array $extensions): bool
    {
        return [] === $extensions || array_any($extensions, static fn (string $extension): bool => str_ends_with($name, '.'.$extension));
    }
}
