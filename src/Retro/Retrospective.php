<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Feedback\FeedbackIndexEntry;
use Jul6Art\DevTools\Feedback\FeedbackRepository;
use Jul6Art\DevTools\Feedback\FeedbackSource;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStat;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * `devtools retro` (ADR-0029): whether feedback goes down. The journal of the tasks delivered, the feedback and its
 * occurrences, the rules and what they did — read, never a scenario run, git only to place a remark in a task.
 */
final readonly class Retrospective
{
    /**
     * A rule neither given nor blocking for this many days is proposed for archiving.
     */
    public const int INACTIVE_DAYS = 30;

    public function __construct(private DevToolsDirectory $directory, private Clock $clock = new SystemClock(), private GitClient $git = new GitClient())
    {
    }

    public function report(\DateTimeImmutable $since, int $inactiveDays = self::INACTIVE_DAYS): RetroReport
    {
        $now = $this->clock->now();
        $tasks = new JournalRepository($this->directory)->since($since);
        $attribution = new Attribution($this->directory->root, $tasks, $this->git);
        $months = self::months($since, $now);
        $human = array_fill_keys($months, 0);
        $byCategory = [];
        $bySource = [];
        $unattributed = 0;
        $repository = new FeedbackRepository($this->directory);
        $index = $repository->index();

        foreach ($index->entries as $entry) {
            $feedback = $repository->get($entry->id);

            foreach ($feedback->occurrences as $occurrence) {
                if ($occurrence->at < $since || $occurrence->at > $now) {
                    continue;
                }

                $bySource[$feedback->category][$occurrence->by->value] = ($bySource[$feedback->category][$occurrence->by->value] ?? 0) + 1;

                if (FeedbackSource::Human !== $occurrence->by) {
                    continue;
                }

                $byCategory[$feedback->category][$occurrence->at->format('Y-m')] = ($byCategory[$feedback->category][$occurrence->at->format('Y-m')] ?? 0) + 1;
                $task = $attribution->taskOf($occurrence, $feedback->target->workflow);

                if ($task instanceof Task && isset($human[$task->month()])) {
                    ++$human[$task->month()];
                } else {
                    ++$unattributed;
                }
            }
        }

        $delivered = array_count_values(array_map(static fn (Task $task): string => $task->month(), $tasks));
        $rules = new RuleRepository($this->directory);

        return new RetroReport(
            $since,
            $now,
            array_map(static fn (string $month): array => ['month' => $month, 'tasks' => $delivered[$month] ?? 0, 'human' => $human[$month]], $months),
            $unattributed,
            self::categories($byCategory, $months),
            self::blocking($tasks, $rules),
            self::inactive($rules, $now, $inactiveDays),
            $inactiveDays,
            self::unpromoted($index->entries),
            \count($tasks),
            array_sum(array_map(static fn (Task $task): int => $task->attempts, $tasks)),
            array_map(static fn (Task $task): array => ['task' => $task->id, 'attempts' => $task->attempts, 'steps' => $task->steps], array_values(array_filter($tasks, static fn (Task $task): bool => $task->attempts > 1))),
            self::verifier($bySource),
        );
    }

    /**
     * @return list<string> `Y-m`, from the month of the first to the month of the last
     */
    private static function months(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $months = [];

        for ($month = $since->modify('first day of this month')->setTime(0, 0); $month->format('Y-m') <= $until->format('Y-m'); $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * @param array<string, array<string, int>> $byCategory category => month => human remarks
     * @param list<string>                      $months
     *
     * @return list<array{category: string, recent: int, before: float, trend: string}>
     */
    private static function categories(array $byCategory, array $months): array
    {
        $last = array_last($months);
        $before = \array_slice($months, -4, 3);
        $categories = [];

        foreach ($byCategory as $category => $counts) {
            $recent = null === $last ? 0 : $counts[$last] ?? 0;
            $mean = [] === $before ? 0.0 : array_sum(array_map(static fn (string $month): int => $counts[$month] ?? 0, $before)) / \count($before);
            $categories[] = ['category' => (string) $category, 'recent' => $recent, 'before' => round($mean, 2), 'trend' => match ($recent <=> $mean) {
                1 => 'up',
                -1 => 'down',
                default => 'steady',
            }];
        }

        usort($categories, static fn (array $a, array $b): int => [$b['recent'], $b['before'], $a['category']] <=> [$a['recent'], $a['before'], $b['category']]);

        return $categories;
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<array{rule: string, tasks: int, blocked: int}>
     */
    private static function blocking(array $tasks, RuleRepository $rules): array
    {
        $stopped = array_count_values(array_merge(...array_map(static fn (Task $task): array => $task->blocked, $tasks)));
        $stats = $rules->stats();
        $ids = array_unique([...array_keys($stopped), ...array_keys(array_filter($stats->rules, static fn (RuleStat $stat): bool => $stat->blocked > 0))]);
        $blocking = array_map(static fn (string $rule): array => ['rule' => $rule, 'tasks' => $stopped[$rule] ?? 0, 'blocked' => $stats->of($rule)->blocked], array_values(array_map(strval(...), $ids)));
        usort($blocking, static fn (array $a, array $b): int => [$b['tasks'], $b['blocked'], $a['rule']] <=> [$a['tasks'], $a['blocked'], $b['rule']]);

        return $blocking;
    }

    /**
     * Active, and neither given to Claude nor blocking for so many days — counted from its creation when it never
     * was.
     *
     * @return list<array{rule: string, last: string, days: int}>
     */
    private static function inactive(RuleRepository $rules, \DateTimeImmutable $now, int $days): array
    {
        $stats = $rules->stats();
        $inactive = [];

        foreach ($rules->index()->entries as $rule) {
            if (RuleStatus::Active !== $rule->status) {
                continue;
            }

            $stat = $stats->of($rule->id);
            $created = \DateTimeImmutable::createFromFormat('!Y-m-d', $rule->created, $now->getTimezone()) ?: $now;
            $last = max([$created, ...array_filter([$stat->lastApplied, $stat->lastBlocked])]);
            $idle = (int) $last->diff($now)->days;

            if ($idle >= $days) {
                $inactive[] = ['rule' => $rule->id, 'last' => $last->format('Y-m-d'), 'days' => $idle];
            }
        }

        usort($inactive, static fn (array $a, array $b): int => [$b['days'], $a['rule']] <=> [$a['days'], $b['rule']]);

        return $inactive;
    }

    /**
     * @param list<FeedbackIndexEntry> $entries
     *
     * @return list<array{id: string, occurrences: int, summary: string}>
     */
    private static function unpromoted(array $entries): array
    {
        $open = array_values(array_filter($entries, static fn (FeedbackIndexEntry $entry): bool => FeedbackStatus::Open === $entry->status && $entry->occurrences >= 2));
        usort($open, static fn (FeedbackIndexEntry $a, FeedbackIndexEntry $b): int => [$b->occurrences, $a->id] <=> [$a->occurrences, $b->id]);

        return array_map(static fn (FeedbackIndexEntry $entry): array => ['id' => $entry->id, 'occurrences' => $entry->occurrences, 'summary' => $entry->summary], $open);
    }

    /**
     * @param array<string, array<string, int>> $bySource category => source => remarks
     *
     * @return list<array{category: string, verifier: int, human: int}>
     */
    private static function verifier(array $bySource): array
    {
        ksort($bySource, \SORT_STRING);

        return array_map(static fn (string $category, array $sources): array => ['category' => $category, 'verifier' => $sources[FeedbackSource::Verifier->value] ?? 0, 'human' => $sources[FeedbackSource::Human->value] ?? 0], array_map(strval(...), array_keys($bySource)), array_values($bySource));
    }
}
