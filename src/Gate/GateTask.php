<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * The task the gate judges (ADR-0029): from the first passage after a green one to the next green one — when it
 * started, at which commit, and what failed on the way. Its green passage, from the `Stop` hook, is a task
 * delivered.
 */
final readonly class GateTask
{
    /**
     * @param string|null  $base    the commit it started at; null outside git
     * @param int          $failed  the passages that failed before
     * @param list<string> $steps   the steps that failed, in the order they first did
     * @param list<string> $blocked the rules whose assertion stopped it
     */
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public ?string $base = null,
        public int $failed = 0,
        public array $steps = [],
        public array $blocked = [],
    ) {
    }

    /**
     * One more passage of this task, failed or not.
     *
     * @param list<GateStep> $steps
     */
    public function after(array $steps, bool $passed): self
    {
        if ($passed) {
            return $this;
        }

        $failed = array_map(static fn (GateStep $step): string => $step->name, array_values(array_filter($steps, static fn (GateStep $step): bool => GateStatus::Failed === $step->status())));

        return new self(
            $this->startedAt,
            $this->base,
            $this->failed + 1,
            array_values(array_unique([...$this->steps, ...$failed])),
            array_values(array_unique([...$this->blocked, ...array_merge(...array_map(static fn (GateStep $step): array => $step->blocked, $steps))])),
        );
    }
}
