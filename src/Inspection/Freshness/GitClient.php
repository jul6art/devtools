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

        if (!$ok) {
            return [];
        }

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
            if ('' !== $path && ('' === $state->prefix || str_starts_with($path, $state->prefix))) {
                $relative[] = substr($path, \strlen($state->prefix));
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
