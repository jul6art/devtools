<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Process\Process;

/**
 * The four questions freshness asks git — whatever the number of workflows (ADR-0010).
 *
 * Git only ever narrows what gets hashed; it never decides that a file is unchanged.
 */
final class GitClient
{
    private int $processes = 0;

    /**
     * Null outside a repository, or when git is not installed.
     */
    public function state(ProjectRoot $root): ?GitState
    {
        [$ok, $output] = $this->git($root, ['rev-parse', 'HEAD', '--abbrev-ref', 'HEAD', '--show-prefix']);
        $lines = explode("\n", rtrim($output, "\n"));

        if (!$ok || 1 !== preg_match('/^[0-9a-f]{40,64}$/', $lines[0])) {
            return null;
        }

        $branch = trim($lines[1] ?? '');

        return new GitState($lines[0], '' === $branch || 'HEAD' === $branch ? null : $branch, trim($lines[2] ?? ''));
    }

    public function commitExists(ProjectRoot $root, string $commit): bool
    {
        return $this->git($root, ['cat-file', '-e', $commit.'^{commit}'])[0];
    }

    /**
     * Files changed between a commit and HEAD, relative to the project root.
     *
     * @return list<string>
     */
    public function changedSince(ProjectRoot $root, string $commit): array
    {
        [$ok, $output] = $this->git($root, ['diff', '--name-only', '--relative', '-z', $commit.'..HEAD']);

        return $ok ? array_values(array_filter(explode("\0", $output))) : [];
    }

    /**
     * Files modified, staged or untracked in the working tree, relative to the project root, the directories
     * DevTools writes excluded — the documentation it just wrote is not a change of the code.
     *
     * @param list<string> $excluded project-relative directories
     *
     * @return list<string>
     */
    public function workingTree(ProjectRoot $root, GitState $state, array $excluded = ['.devtools']): array
    {
        $exclusions = array_map(static fn (string $directory): string => ':(exclude)'.trim($directory, '/'), $excluded);
        [$ok, $output] = $this->git($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all', '--', '.', ...$exclusions]);

        return $ok ? self::statusPaths($output, $state->prefix) : [];
    }

    /**
     * The files of one commit — the root commit included — relative to the project, in one process
     * (ADR-0016). Null when git does not know the reference.
     *
     * @return list<string>|null
     */
    public function filesOfCommit(ProjectRoot $root, string $reference): ?array
    {
        [$ok, $output] = $this->git($root, ['diff-tree', '--root', '--no-commit-id', '--name-only', '-r', '-z', '--relative', $reference]);

        return $ok ? self::nameList($output) : null;
    }

    /**
     * The files changed by a range of commits (`a..b`), relative to the project, in one process (ADR-0016).
     *
     * @return list<string>|null
     */
    public function filesOfRange(ProjectRoot $root, string $range): ?array
    {
        [$ok, $output] = $this->git($root, ['diff', '--name-only', '-z', '--relative', $range]);

        return $ok ? self::nameList($output) : null;
    }

    /**
     * Files modified, staged or untracked, relative to the project, in ONE process (ADR-0016): the project's
     * place in its repository is read from the disk — the `.git` above it — instead of asking git. Null
     * outside a repository.
     *
     * @return list<string>|null
     */
    public function workingTreeChanges(ProjectRoot $root): ?array
    {
        $prefix = self::prefixOf($root->path);

        if (null === $prefix) {
            return null;
        }

        [$ok, $output] = $this->git($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all', '--', '.']);

        return $ok ? self::statusPaths($output, $prefix) : null;
    }

    /**
     * The files of the project git knows of — tracked, or new and not ignored — relative to it, in one process
     * (ADR-0026). Null outside a repository. A path in conflict, listed once per stage of the merge, is listed
     * once.
     *
     * @return list<string>|null
     */
    public function files(ProjectRoot $root): ?array
    {
        [$ok, $output] = $this->git($root, ['ls-files', '--cached', '--others', '--exclude-standard', '-z']);

        return $ok ? array_values(array_unique(self::nameList($output))) : null;
    }

    /**
     * The files of the project as a commit left them, relative to it, in one process (ADR-0027). Null when git
     * does not know the commit.
     *
     * @return list<string>|null
     */
    public function filesAt(ProjectRoot $root, string $commit): ?array
    {
        [$ok, $output] = $this->git($root, ['ls-tree', '-r', '--name-only', '-z', $commit, '--', '.']);

        return $ok ? self::nameList($output) : null;
    }

    /**
     * A file of the project as a commit left it (ADR-0027). Null when it did not exist then.
     */
    public function show(ProjectRoot $root, string $commit, string $path): ?string
    {
        [$ok, $output] = $this->git($root, ['show', $commit.':./'.$path]);

        return $ok ? $output : null;
    }

    /**
     * The commits reachable from one and not from the other (`git rev-list from..to`), in one process (ADR-0029).
     * Null when git does not know one of them.
     *
     * @return list<string>|null
     */
    public function commitsBetween(ProjectRoot $root, string $from, string $to): ?array
    {
        [$ok, $output] = $this->git($root, ['rev-list', $from.'..'.$to]);

        return $ok ? preg_split('/\n/', $output, -1, \PREG_SPLIT_NO_EMPTY) ?: [] : null;
    }

    /**
     * The directory of the project inside its repository, `app/` or `''` — from the nearest `.git`, a
     * directory or the file of a worktree.
     */
    private static function prefixOf(string $path): ?string
    {
        $directory = $path;

        while (!file_exists($directory.'/.git')) {
            $parent = \dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        return $directory === $path ? '' : substr($path, \strlen($directory) + 1).'/';
    }

    /**
     * @return list<string>
     */
    private static function nameList(string $output): array
    {
        $paths = array_values(array_filter(explode("\0", $output)));
        sort($paths, \SORT_STRING);

        return $paths;
    }

    /**
     * The paths of `git status --porcelain=v1 -z`, relative to the project: a rename or a copy names its
     * original path too.
     *
     * @return list<string>
     */
    private static function statusPaths(string $output, string $prefix): array
    {
        $paths = [];
        $entries = explode("\0", $output);

        for ($i = 0, $count = \count($entries); $i < $count; ++$i) {
            $entry = $entries[$i];

            if (\strlen($entry) < 4) {
                continue;
            }

            $paths[] = substr($entry, 3);

            // A rename or a copy is followed by its original path.
            if ('R' === $entry[0] || 'C' === $entry[0]) {
                $paths[] = $entries[++$i] ?? '';
            }
        }

        $relative = [];

        foreach ($paths as $path) {
            if ('' !== $path && ('' === $prefix || str_starts_with($path, $prefix))) {
                $relative[] = substr($path, \strlen($prefix));
            }
        }

        return array_values(array_unique($relative));
    }

    /**
     * What changed in these files since a commit, as git prints it (ADR-0046).
     *
     * The working tree is the other side, not HEAD: the inspection reads the files as they are on disk,
     * so the diff must show what it read.
     *
     * @param list<string> $paths
     */
    public function diff(ProjectRoot $root, string $commit, array $paths): string
    {
        if ([] === $paths) {
            return '';
        }

        [$ok, $output] = $this->git($root, ['diff', '--no-color', '--relative', $commit, '--', ...$paths]);

        return $ok ? $output : '';
    }

    /**
     * Brings files back to the state a commit left them in (ADR-0047).
     *
     * ⚠️ The only thing in DevTools that touches a project's own code. It is called from one place, behind
     * one explicit option, and it says whether git accepted.
     *
     * @param list<string> $paths
     */
    public function restore(ProjectRoot $root, string $commit, array $paths): bool
    {
        return [] !== $paths && $this->git($root, ['restore', '--source='.$commit, '--', ...$paths])[0];
    }

    public function processes(): int
    {
        return $this->processes;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{bool, string}
     */
    private function git(ProjectRoot $root, array $arguments): array
    {
        ++$this->processes;
        $process = new Process(['git', ...$arguments], $root->path);

        try {
            $process->run();
        } catch (\Throwable) {
            return [false, ''];
        }

        return [$process->isSuccessful(), $process->getOutput()];
    }
}
