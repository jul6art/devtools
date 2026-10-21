<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

/**
 * What a retrospective found (ADR-0029), section by section — rendered as Markdown or XML.
 */
final readonly class RetroReport
{
    /**
     * @param list<array{month: string, tasks: int, human: int}>                       $months       tasks delivered, and the human remarks attributed to them
     * @param int                                                                      $unattributed human remarks attributed to no task
     * @param list<array{category: string, recent: int, before: float, trend: string}> $categories   human remarks of the last month, against the monthly mean of the three before
     * @param list<array{rule: string, tasks: int, blocked: int}>                      $blocking     tasks of the period each rule stopped, and all it ever blocked
     * @param list<array{rule: string, last: string, days: int}>                       $inactive     active rules neither given nor blocking for `$inactiveDays` days
     * @param list<array{id: string, occurrences: int, summary: string}>               $unpromoted   open feedback seen twice or more
     * @param list<array{task: string, attempts: int, steps: list<string>}>            $passes       the tasks that took more than one passage of the gate
     * @param list<array{category: string, verifier: int, human: int}>                 $verifier     remarks by source and category
     */
    public function __construct(
        public \DateTimeImmutable $since,
        public \DateTimeImmutable $until,
        public array $months,
        public int $unattributed,
        public array $categories,
        public array $blocking,
        public array $inactive,
        public int $inactiveDays,
        public array $unpromoted,
        public int $tasks,
        public int $attempts,
        public array $passes,
        public array $verifier,
    ) {
    }

    /**
     * @param array{month: string, tasks: int, human: int} $month
     */
    public static function perTask(array $month): ?float
    {
        return 0 === $month['tasks'] ? null : $month['human'] / $month['tasks'];
    }

    /**
     * The last month against the mean of the three before (§ 8: −50 % in three months); null without both.
     *
     * @return array{month: string, perTask: float, before: float, change: int}|null
     */
    public function trend(): ?array
    {
        $last = array_last($this->months);
        $before = array_values(array_filter(array_map(self::perTask(...), \array_slice($this->months, -4, 3)), \is_float(...)));
        $now = null === $last ? null : self::perTask($last);

        if (null === $last || null === $now || \count($this->months) < 4 || [] === $before) {
            return null;
        }

        $mean = array_sum($before) / \count($before);

        return ['month' => $last['month'], 'perTask' => $now, 'before' => $mean, 'change' => 0.0 === $mean ? 0 : (int) round(100 * ($now - $mean) / $mean)];
    }
}
