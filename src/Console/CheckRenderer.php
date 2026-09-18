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
     * @param list<ChangeGroup> $groups
     * @param list<string>      $undocumented workflows whose page was never written by Claude
     *
     * @return list<string> one line per cause, empty when there is nothing to report
     */
    public static function causes(InspectionReport $report, array $groups, array $undocumented = []): array
    {
        $lines = [];

        foreach ($report->decisions as $id => $decision) {
            $lines[] = match ($decision->kind) {
                DecisionKind::Create => \sprintf('%s non documenté', self::pad($id)),
                DecisionKind::Orphan => \sprintf('%s orphelin', self::pad($id)),
                default => null,
            };
        }

        foreach ($undocumented as $id) {
            $lines[] = \sprintf('%s jamais rédigé', self::pad($id));
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
     *
     * @return list<string>
     */
    public static function annotations(InspectionReport $report, array $groups, array $undocumented = []): array
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
