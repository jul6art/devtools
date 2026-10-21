<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

/**
 * The retrospective as a human reads it (ADR-0029, specs § 5.5): `.devtools/reports/retro-<date>.md`.
 */
final class RetroMarkdown
{
    public static function render(RetroReport $report): string
    {
        $lines = [\sprintf('# Retrospective — %s to %s', $report->since->format('Y-m-d'), $report->until->format('Y-m-d')), ''];

        $lines[] = '## Human feedback per delivered task';
        $lines[] = '';
        $lines[] = '| Month | Tasks delivered | Human remarks | Per task |';
        $lines[] = '| --- | ---: | ---: | ---: |';

        foreach ($report->months as $month) {
            $perTask = RetroReport::perTask($month);
            $lines[] = \sprintf('| %s | %d | %d | %s |', $month['month'], $month['tasks'], $month['human'], null === $perTask ? '—' : number_format($perTask, 2));
        }

        $lines[] = '';
        $trend = $report->trend();
        $lines[] = null === $trend
            ? 'No trend yet: it takes the last month and the three before it, each with a task delivered.'
            : \sprintf('%s: %s remarks per task, against %s over the three months before — %+d %% (the target: -50 %% in three months).', $trend['month'], number_format($trend['perTask'], 2), number_format($trend['before'], 2), $trend['change']);

        if ($report->unattributed > 0) {
            $lines[] = '';
            $lines[] = \sprintf('%s about no delivered task: made before any, or about nothing a task reached.', 1 === $report->unattributed ? '1 human remark is' : $report->unattributed.' human remarks are');
        }

        $lines[] = '';
        $lines[] = '## Recurring categories';
        $lines[] = '';
        $lines = [...$lines, ...self::table(
            ['Category', 'Last month', 'Monthly, the three months before', 'Trend'],
            array_map(static fn (array $category): array => [$category['category'], (string) $category['recent'], number_format($category['before'], 2), $category['trend']], $report->categories),
            'No human remark in the period.',
        )];

        $lines[] = '';
        $lines[] = '## Rules that blocked the gate';
        $lines[] = '';
        $lines = [...$lines, ...self::table(
            ['Rule', 'Tasks it stopped in the period', 'Blocked, in all'],
            array_map(static fn (array $rule): array => [$rule['rule'], (string) $rule['tasks'], (string) $rule['blocked']], $report->blocking),
            'No rule blocked the gate: none has an assertion yet, or none was needed.',
        )];

        $lines[] = '';
        $lines[] = '## Inactive rules — proposed for archiving';
        $lines[] = '';
        $lines = [...$lines, ...self::table(
            ['Rule', 'Last given or blocking', 'Days since'],
            array_map(static fn (array $rule): array => [$rule['rule'], $rule['last'], (string) $rule['days']], $report->inactive),
            \sprintf('Every active rule was given to Claude or blocked a change in the last %d days.', $report->inactiveDays),
        )];

        if ([] !== $report->inactive) {
            $lines[] = '';
            $lines[] = '`devtools rules:archive <rule>` archives one. Nothing is archived on its own.';
        }

        $lines[] = '';
        $lines[] = '## Feedback seen twice, not promoted';
        $lines[] = '';
        $lines = [...$lines, ...self::table(
            ['Feedback', 'Seen', 'Summary'],
            array_map(static fn (array $feedback): array => [$feedback['id'], (string) $feedback['occurrences'], $feedback['summary']], $report->unpromoted),
            'None: everything said twice is a rule, or closed.',
        )];

        if ([] !== $report->unpromoted) {
            $lines[] = '';
            $lines[] = '`devtools rules:promote <feedback>` asks Claude for the rule.';
        }

        $lines[] = '';
        $lines[] = '## Gate failures before success';
        $lines[] = '';
        $lines[] = 0 === $report->tasks
            ? 'No task delivered in the period.'
            : \sprintf('%d tasks delivered, %d at the first passage of the gate; %s passages per task on average.', $report->tasks, $report->tasks - \count($report->passes), number_format($report->attempts / $report->tasks, 2));

        if ([] !== $report->passes) {
            $lines[] = '';
            $lines = [...$lines, ...self::table(
                ['Task', 'Passages', 'Steps that failed'],
                array_map(static fn (array $task): array => [$task['task'], (string) $task['attempts'], implode(', ', $task['steps'])], $report->passes),
                '',
            )];
        }

        $lines[] = '';
        $lines[] = '## The verifier and the humans';
        $lines[] = '';
        $lines = [...$lines, ...self::table(
            ['Category', 'Found by the verifier', 'Said by a human'],
            array_map(static fn (array $category): array => [$category['category'], (string) $category['verifier'], (string) $category['human']], $report->verifier),
            'No remark in the period.',
        )];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     *
     * @return list<string>
     */
    private static function table(array $headers, array $rows, string $empty): array
    {
        if ([] === $rows) {
            return [$empty];
        }

        return [
            '| '.implode(' | ', $headers).' |',
            '| '.implode(' | ', array_fill(0, \count($headers), '---')).' |',
            ...array_map(static fn (array $row): string => '| '.implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '\|', $cell), $row)).' |', $rows),
        ];
    }
}
