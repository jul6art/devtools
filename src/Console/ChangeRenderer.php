<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Diff\ChangeNature;
use Jul6Art\DevTools\Inspection\Diff\ChangeSubject;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * What `workflows:diff` prints (ADR-0046): one block per changed fact, widest first, and the workflows
 * it reaches — never one block per workflow, which on a real project means 233 blocks for one line.
 */
final readonly class ChangeRenderer
{
    private const int NAMED_WORKFLOWS = 3;

    public function __construct(private GitClient $git = new GitClient())
    {
    }

    /**
     * @param list<ChangeGroup> $groups
     */
    public function text(InspectionReport $report, array $groups, string $path, bool $code = false): string
    {
        $lines = [
            '',
            \sprintf(' <options=bold>DevTools — %s</>   workflow diff', $report->projectName),
            '',
            \sprintf(
                ' %s %s · %s',
                [] === $groups ? '<info>✔</info>' : '<comment>⚠</comment>',
                self::factCount(\count($groups)),
                self::workflowCount(\count($report->changes)),
            ),
            \sprintf(' <fg=gray>%d files parsed · %d hashed</>', $report->filesParsed, $report->filesHashed),
            '',
        ];

        if ([] === $groups) {
            $lines[] = ' <info>No fact changed.</info>';
        }

        $diffs = $code ? $this->diffs($report, $groups, $path) : [];

        foreach ($groups as $group) {
            $change = $group->change;
            $lines[] = \sprintf(
                ' <options=bold>%s</> <fg=cyan>%s</> %s%s',
                self::natureOf($change->nature),
                self::subjectOf($change->subject),
                $change->target,
                null === $change->declaredIn ? '' : \sprintf('  <fg=gray>%s%s</>', $change->declaredIn->path, null === $change->line ? '' : ':'.$change->line),
            );

            if (null !== $change->before) {
                $lines[] = \sprintf('   <fg=red>- %s</>', $change->before);
            }

            if (null !== $change->after) {
                $lines[] = \sprintf('   <fg=green>+ %s</>', $change->after);
            }

            $lines[] = '   <fg=gray>'.self::reach($group).'</>';

            foreach (explode("\n", rtrim($diffs[$group->change->key()] ?? '', "\n")) as $line) {
                if ('' !== $line) {
                    $lines[] = '   <fg=gray>│</> '.self::colour($line);
                }
            }

            $lines[] = '';
        }

        foreach (self::appearances($report) as $line) {
            $lines[] = ' '.$line;
        }

        return implode("\n", [...$lines, '']);
    }

    /**
     * @param list<ChangeGroup> $groups
     */
    public function markdown(InspectionReport $report, array $groups, string $path, bool $code = false): string
    {
        $lines = ['# Workflows — what changed', '', \sprintf('%s · %s.', self::factCount(\count($groups)), self::workflowCount(\count($report->changes))), ''];

        if ([] === $groups) {
            $lines[] = 'No fact changed.';
        }

        $diffs = $code ? $this->diffs($report, $groups, $path) : [];

        foreach ($groups as $group) {
            $change = $group->change;
            $lines[] = \sprintf('## %s %s `%s`', self::natureOf($change->nature), self::subjectOf($change->subject), $change->target);
            $lines[] = '';

            if (null !== $change->declaredIn) {
                $lines[] = \sprintf('`%s%s`', $change->declaredIn->path, null === $change->line ? '' : ':'.$change->line);
                $lines[] = '';
            }

            if (null !== $change->before || null !== $change->after) {
                $lines = [...$lines, '```diff', ...(null === $change->before ? [] : ['- '.$change->before]), ...(null === $change->after ? [] : ['+ '.$change->after]), '```', ''];
            }

            $lines[] = self::reach($group);
            $lines[] = '';

            if ('' !== ($diffs[$change->key()] ?? '')) {
                $lines = [...$lines, '```diff', rtrim($diffs[$change->key()], "\n"), '```', ''];
            }
        }

        foreach (self::appearances($report) as $line) {
            $lines[] = '- '.$line;
        }

        return implode("\n", [...$lines, '']);
    }

    /**
     * The code behind each fact, taken from the commit the page was written at (ADR-0046 § 7).
     *
     * ⚠️ One git process per distinct reference, never one per fact: the tracking files of a project
     * almost always share the same commit, and the output is split per file afterwards.
     *
     * @param list<ChangeGroup> $groups
     *
     * @return array<string, string> change key => the diff of the file it lives in
     */
    private function diffs(InspectionReport $report, array $groups, string $path): array
    {
        $root = new ProjectRoot($path);
        $filesByCommit = [];

        foreach ($groups as $group) {
            $file = $group->change->declaredIn?->path;
            $commit = self::commitOf($report, $group);

            if (null !== $file && null !== $commit) {
                $filesByCommit[$commit][$file] = true;
            }
        }

        $byFile = [];

        foreach ($filesByCommit as $commit => $files) {
            foreach (self::split($this->git->diff($root, $commit, array_keys($files))) as $file => $diff) {
                $byFile[$commit."\0".$file] = $diff;
            }
        }

        $diffs = [];

        foreach ($groups as $group) {
            $diffs[$group->change->key()] = $byFile[self::commitOf($report, $group)."\0".($group->change->declaredIn->path ?? '')] ?? '';
        }

        return $diffs;
    }

    private static function commitOf(InspectionReport $report, ChangeGroup $group): ?string
    {
        foreach ($group->workflows as $id) {
            if (isset($report->writtenFrom[$id])) {
                return $report->writtenFrom[$id];
            }
        }

        return null;
    }

    /**
     * `git diff` of several files, cut back into one diff per file.
     *
     * @return array<string, string>
     */
    private static function split(string $diff): array
    {
        $byFile = [];
        $current = null;

        foreach (explode("\n", $diff) as $line) {
            if (1 === preg_match('#^diff --git a/(\S+) b/#', $line, $matches)) {
                $current = $matches[1];
                $byFile[$current] = '';

                continue;
            }

            if (null !== $current) {
                $byFile[$current] .= $line."\n";
            }
        }

        return $byFile;
    }

    /**
     * @return list<string>
     */
    private static function appearances(InspectionReport $report): array
    {
        $lines = [];

        foreach ($report->decisions as $id => $decision) {
            $lines[] = match ($decision->kind) {
                DecisionKind::Create => \sprintf('<info>new</info> %s', $id),
                DecisionKind::Orphan => \sprintf('<fg=red>gone</> %s', $id),
                default => null,
            };
        }

        return array_values(array_filter($lines, static fn (?string $line): bool => null !== $line));
    }

    private static function reach(ChangeGroup $group): string
    {
        $named = \array_slice($group->workflows, 0, self::NAMED_WORKFLOWS);
        $rest = $group->count() - \count($named);

        return \sprintf('%s · %s%s', self::workflowCount($group->count()), implode(', ', $named), $rest > 0 ? \sprintf(' (+%d)', $rest) : '');
    }

    private static function colour(string $line): string
    {
        return match (true) {
            str_starts_with($line, '+') => '<fg=green>'.$line.'</>',
            str_starts_with($line, '-') => '<fg=red>'.$line.'</>',
            default => '<fg=gray>'.$line.'</>',
        };
    }

    private static function factCount(int $count): string
    {
        return 0 === $count ? 'no fact changed' : \sprintf('%d fact%s changed', $count, 1 === $count ? '' : 's');
    }

    private static function workflowCount(int $count): string
    {
        return \sprintf('%d workflow%s', $count, 1 === $count ? '' : 's');
    }

    public static function natureOf(ChangeNature $nature): string
    {
        return match ($nature) {
            ChangeNature::Added => 'added',
            ChangeNature::Removed => 'removed',
            ChangeNature::Modified => 'modified',
        };
    }

    public static function subjectOf(ChangeSubject $subject): string
    {
        return match ($subject) {
            ChangeSubject::EntryPoint => 'entry point',
            ChangeSubject::Attribute => 'attribute',
            ChangeSubject::Decision => 'decision',
            ChangeSubject::Mechanism => 'mechanism',
            ChangeSubject::Dependency => 'dependency',
            ChangeSubject::Test => 'test',
            ChangeSubject::Package => 'package',
        };
    }
}
