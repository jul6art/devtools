<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Project;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;

/**
 * The files of a project (ADR-0026): those git knows of — tracked, or new and not ignored — or, outside a
 * repository, those on the disk. Either way, never one under an excluded directory.
 */
final readonly class ProjectFiles
{
    public function __construct(private GitClient $git = new GitClient())
    {
    }

    /**
     * @param list<string> $excludes project-relative directories, {@see Config::excludes()}
     *
     * @return list<string> relative to the project, sorted
     */
    public function of(ProjectRoot $root, array $excludes = Config::DEFAULT_EXCLUDES): array
    {
        $files = array_filter(
            $this->git->files($root) ?? self::onDisk($root, $excludes),
            static fn (string $path): bool => is_file($root->absolute($path)) && !array_any($excludes, static fn (string $directory): bool => str_starts_with($path, $directory.'/')),
        );
        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $excludes
     *
     * @return list<string>
     */
    private static function onDisk(ProjectRoot $root, array $excludes): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root->path, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !$file->isDir() || !\in_array($root->relative($file->getPathname()), $excludes, true),
        ));
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $root->relative($file->getPathname());
            }
        }

        return $files;
    }
}
