<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A throwaway git repository for a test: never this repository, never the developer's global configuration.
 */
final readonly class GitRepository
{
    private function __construct(public string $path)
    {
    }

    public static function initialise(string $path): self
    {
        $repository = new self($path);
        $repository->git('init', '--quiet', '--initial-branch=main');
        $repository->git('config', 'user.email', 'tests@devtools.invalid');
        $repository->git('config', 'user.name', 'DevTools tests');
        $repository->git('config', 'commit.gpgsign', 'false');
        $repository->commitAll('initial');

        return $repository;
    }

    public function commitAll(string $message): string
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '-m', $message);

        return $this->head();
    }

    public function head(): string
    {
        return trim($this->git('rev-parse', 'HEAD'));
    }

    /**
     * Rewrites the last commit and purges the old one from the object database, as a rebase then a gc would.
     */
    public function rewriteHistory(): void
    {
        // Everything is committed first: the rewritten commit must carry the changes, so that the working
        // tree says nothing and only the vanished commit is left to explain them.
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--amend', '--allow-empty', '-m', 'rewritten');
        $this->git('reflog', 'expire', '--expire=now', '--all');
        $this->git('gc', '--quiet', '--prune=now');
    }

    public function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->path, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);
        $process->mustRun();

        return $process->getOutput();
    }
}
