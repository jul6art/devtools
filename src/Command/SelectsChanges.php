<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * A change, as the commands that judge one take it (ADR-0016, ADR-0023, ADR-0024): files given by name, the
 * files of a commit or of a range, or the working tree — one source, never two.
 *
 * @internal
 */
trait SelectsChanges
{
    /**
     * A reference git reads, never an option it would take one for.
     */
    private static string $reference = '[A-Za-z0-9_][A-Za-z0-9._\/~^@{}-]*';

    private function configureChanges(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY, 'Files of the project, relative to it or absolute')
            ->addOption('commit', null, InputOption::VALUE_REQUIRED, 'The files of this commit')
            ->addOption('range', null, InputOption::VALUE_REQUIRED, 'The files of this range of commits: a..b')
            ->addOption('changed', null, InputOption::VALUE_NONE, 'The files modified, staged or untracked in the working tree');
    }

    /**
     * Why the change asked for cannot be read as asked, or null.
     */
    private function refuseChanges(InputInterface $input): ?string
    {
        $commit = $input->getOption('commit');
        $range = $input->getOption('range');

        return match (true) {
            1 !== \count(array_filter([[] !== self::named($input), \is_string($commit), \is_string($range), true === $input->getOption('changed')])) => 'Give files, or one of --commit, --range, --changed.',
            \is_string($commit) && 1 !== preg_match('/^'.self::$reference.'$/', $commit) => \sprintf('"%s" is not a commit reference.', $commit),
            \is_string($range) && 1 !== preg_match('/^'.self::$reference.'\.\.\.?'.self::$reference.'$/', $range) => \sprintf('"%s" is not a range: a..b.', $range),
            default => null,
        };
    }

    /**
     * @return list<string>|null the files of the change, null when git could not answer
     */
    private function changes(InputInterface $input, ProjectRoot $root, GitClient $git): ?array
    {
        $commit = $input->getOption('commit');
        $range = $input->getOption('range');

        return match (true) {
            \is_string($commit) => $git->filesOfCommit($root, $commit),
            \is_string($range) => $git->filesOfRange($root, $range),
            true === $input->getOption('changed') => $git->workingTreeChanges($root),
            default => self::named($input),
        };
    }

    /**
     * @return list<string>
     */
    private static function named(InputInterface $input): array
    {
        $files = $input->getArgument('files');

        return \is_array($files) ? array_values(array_filter($files, \is_string(...))) : [];
    }
}
