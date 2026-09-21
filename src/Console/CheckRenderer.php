<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\InspectionReport;

/**
 * What `workflows:check` prints (ADR-0017): the causes of its failure, one line each.
 *
 * ⚠️ A file whose bytes changed without changing a fact is not a cause. The freshness would report it;
 * a gate that reports it wakes people up for a comment, and a gate one ignores is not a gate.
 */
final readonly class CheckRenderer
{
    /**
     * The workflows whose files changed without changing a single fact (ADR-0017).
     *
     * ⚠️ Not a cause of failure by default, and reported all the same: the prose of a page can become
     * false without any fact moving — a condition rewritten inside a method, a constant renamed, a
     * default value flipped. The gate says how many, `--strict` makes them fail.
     *
     * @return list<string>
     */
    public static function silent(InspectionReport $report): array
    {
        $silent = [];

        foreach ($report->decisions as $id => $decision) {
            if (DecisionKind::Rewrite === $decision->kind && !isset($report->changes[$id])) {
                $silent[] = $id;
            }
        }

        return $silent;
    }

    /**
     * @param list<ChangeGroup> $groups
     * @param list<string>      $undocumented workflows whose page was never written by Claude
     * @param list<string>      $silent       workflows whose code changed without changing a fact, when --strict asks for them
     *
     * @return list<string> one line per cause, empty when there is nothing to report
     */
    public static function causes(InspectionReport $report, array $groups, array $undocumented = [], array $silent = []): array
    {
        $lines = [];

        foreach ($report->decisions as $id => $decision) {
            $lines[] = match ($decision->kind) {
                DecisionKind::Create => \sprintf('%s not documented', self::pad($id)),
                DecisionKind::Orphan => \sprintf('%s orphaned', self::pad($id)),
                default => null,
            };
        }

        foreach ($undocumented as $id) {
            $lines[] = \sprintf('%s never written', self::pad($id));
        }

        foreach ($silent as $id) {
            $lines[] = \sprintf('%s code changed, no fact', self::pad($id));
        }

        foreach ($groups as $group) {
            $change = $group->change;
            $lines[] = trim(\sprintf(
                '%s %s %s%s   %d workflow%s',
                ChangeRenderer::natureOf($change->nature),
                ChangeRenderer::subjectOf($change->subject),
                $change->target,
                null === $change->before && null === $change->after ? '' : \sprintf('   %s → %s', $change->before ?? '—', $change->after ?? '—'),
                $group->count(),
                1 === $group->count() ? '' : 's',
            ));
        }

        return array_values(array_filter($lines, static fn (?string $line): bool => null !== $line));
    }

    /**
     * The same causes as GitHub annotations, so that a pull request shows the line in question.
     *
     * @param list<ChangeGroup> $groups
     * @param list<string>      $undocumented
     * @param list<string>      $silent
     *
     * @return list<string>
     */
    public static function annotations(InspectionReport $report, array $groups, array $undocumented = [], array $silent = []): array
    {
        $lines = [];

        foreach ($report->decisions as $id => $decision) {
            $file = $report->entryFiles[$id] ?? null;
            $message = match ($decision->kind) {
                DecisionKind::Create => \sprintf('%s is not documented: run devtools workflows:inspect.', $id),
                DecisionKind::Orphan => \sprintf('%s no longer exists in the code: its page is orphaned.', $id),
                default => null,
            };

            if (null !== $message) {
                $lines[] = self::annotation($message, DecisionKind::Create === $decision->kind ? $file : null, null);
            }
        }

        foreach ($undocumented as $id) {
            $lines[] = self::annotation(\sprintf('%s has never been written: only its facts are documented.', $id), $report->entryFiles[$id] ?? null, null);
        }

        foreach ($silent as $id) {
            $lines[] = self::annotation(\sprintf('%s: its files changed without changing a documented fact; its prose may be stale.', $id), $report->entryFiles[$id] ?? null, null);
        }

        foreach ($groups as $group) {
            $change = $group->change;
            $lines[] = self::annotation(
                \sprintf(
                    '%s %s %s%s (%d workflow%s)',
                    $change->nature->value,
                    $change->subject->value,
                    $change->target,
                    null === $change->before && null === $change->after ? '' : \sprintf(': %s -> %s', $change->before ?? '—', $change->after ?? '—'),
                    $group->count(),
                    1 === $group->count() ? '' : 's',
                ),
                $change->declaredIn?->path,
                $change->line,
            );
        }

        return $lines;
    }

    private static function annotation(string $message, ?string $file, ?int $line): string
    {
        $location = null === $file ? '' : ' file='.$file.(null === $line ? '' : ',line='.$line);

        // A newline in an annotation would be read as the end of it: GitHub asks for %0A.
        return \sprintf('::error%s::%s', $location, str_replace("\n", '%0A', $message));
    }

    private static function pad(string $id): string
    {
        return str_pad($id, 42);
    }
}
