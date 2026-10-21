<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * The project as a commit left it, read from git without touching the working tree: where an assertion proposed
 * for a feedback must fail (ADR-0027).
 */
final class CommitTree implements FileSource
{
    /**
     * @var list<string>|null
     */
    private ?array $files = null;

    /**
     * @param list<string> $excludes
     */
    public function __construct(
        private readonly ProjectRoot $root,
        public readonly string $commit,
        private readonly GitClient $git = new GitClient(),
        private readonly array $excludes = Config::DEFAULT_EXCLUDES,
    ) {
    }

    #[\Override]
    public function files(): array
    {
        return $this->files ??= array_values(array_filter(
            $this->git->filesAt($this->root, $this->commit) ?? [],
            fn (string $path): bool => !array_any($this->excludes, static fn (string $directory): bool => str_starts_with($path, $directory.'/')),
        ));
    }

    #[\Override]
    public function read(string $path): ?string
    {
        return $this->git->show($this->root, $this->commit, $path);
    }

    #[\Override]
    public function isCurrent(): bool
    {
        return false;
    }
}
