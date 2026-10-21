<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectFiles;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * The project as it is on the disk.
 */
final class WorkingTree implements FileSource
{
    /**
     * @var list<string>|null
     */
    private ?array $files = null;

    /**
     * @param list<string> $excludes
     */
    public function __construct(private readonly ProjectRoot $root, private readonly array $excludes = Config::DEFAULT_EXCLUDES, private readonly ProjectFiles $projectFiles = new ProjectFiles())
    {
    }

    #[\Override]
    public function files(): array
    {
        return $this->files ??= $this->projectFiles->of($this->root, $this->excludes);
    }

    #[\Override]
    public function read(string $path): ?string
    {
        try {
            $file = $this->root->absolute($path);
        } catch (PathOutsideProject) {
            return null;
        }

        $content = is_file($file) ? file_get_contents($file) : false;

        return false === $content ? null : $content;
    }

    #[\Override]
    public function isCurrent(): bool
    {
        return true;
    }
}
