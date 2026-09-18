<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The block a command ends on: what it did, what it cost, and what went wrong (ADR-0042).
 *
 * The colour is the one of the exit code — green when nothing was reported, yellow on a warning or a
 * fallback, red on an error — so a reader who only glances at the last line already knows.
 *
 * With `--no-ansi` the very same text comes out, symbols included: only the colours go.
 */
final class SummaryRenderer
{
    public function inspection(SymfonyStyle $io, InspectionReport $report, string $stacks): void
    {
        $mark = self::mark($report->exitCode());
        $io->newLine();
        $io->writeln(\sprintf(' <options=bold>DevTools — %s</>%s', $report->projectName, '' === $stacks ? '' : '   '.$stacks));
        $io->newLine();

        $total = array_sum(array_map(static fn (string $outcome): int => $report->count($outcome), InspectionReport::OUTCOMES));
        $io->writeln(\sprintf(
            ' %s %s workflows      %s créés · %s mis à jour · %s inchangés · %s orphelins',
            $mark,
            self::number($total),
            self::number($report->count('created')),
            self::number($report->count('updated')),
            self::number($report->count('unchanged')),
            self::number($report->count('orphaned')),
        ));
        $io->writeln(\sprintf(
            ' %s %s fichiers parcourus · %s hachés · %s processus git · %s appels console',
            $mark,
            self::number($report->filesParsed),
            self::number($report->filesHashed),
            self::number($report->gitProcesses),
            self::number($report->consoleCalls),
        ));

        if (0 < $report->briefsWritten) {
            $io->writeln(\sprintf(
                ' %s %s briefs écrits pour Claude · %s (~%s tokens estimés)',
                $mark,
                self::number($report->briefsWritten),
                self::bytes($report->briefBytes),
                self::number($report->estimatedTokens()),
            ));
        }

        foreach ($report->fallbacks as $fallback) {
            $io->writeln(\sprintf(' <comment>⚠</comment> repli statique (%s)', $fallback['stack']));
        }

        $io->newLine();
        $io->writeln(\sprintf(
            ' Durée %s s · mémoire %s%s',
            number_format($report->seconds, 1, ',', ' '),
            self::bytes($report->peakMemoryBytes),
            null === $report->path ? '' : ' · rapport '.$report->path,
        ));
        $io->newLine();
    }

    public function apply(SymfonyStyle $io, ApplyResult $result, float $seconds): void
    {
        $mark = self::mark([] !== $result->refused ? 2 : ([] !== $result->warnings ? 1 : 0));
        $io->newLine();
        $io->writeln(\sprintf(
            ' %s %s brouillons appliqués · %s refusés · %s fiches déposées',
            $mark,
            self::number(\count($result->accepted)),
            self::number(\count($result->refused)),
            self::number(\count($result->deposited)),
        ));
        $io->writeln(\sprintf(' Durée %s s', number_format($seconds, 1, ',', ' ')));
        $io->newLine();
    }

    /**
     * Green, yellow or red — the colour of the exit code the command is about to return.
     */
    private static function mark(int $exitCode): string
    {
        return match (true) {
            2 <= $exitCode => '<fg=red>✖</>',
            1 === $exitCode => '<comment>⚠</comment>',
            default => '<info>✔</info>',
        };
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    private static function bytes(int $value): string
    {
        return match (true) {
            $value >= 1048576 => number_format($value / 1048576, 0, ',', ' ').' Mo',
            $value >= 1024 => number_format($value / 1024, 0, ',', ' ').' Ko',
            default => $value.' o',
        };
    }
}
