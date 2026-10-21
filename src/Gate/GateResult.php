<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * One passage of the gate (ADR-0023): every step, the change it judged, and how many times in a row it failed
 * the very same way — what the Stop hook reads before letting Claude end a task.
 */
final readonly class GateResult
{
    /**
     * @param list<string>   $changed     the files of the change, relative to the project
     * @param list<GateStep> $steps       in the order they ran
     * @param string         $changes     a fingerprint of the files of the change, as they are
     * @param int            $consecutive how many passages in a row failed exactly like this one; 0 when it passed
     */
    public function __construct(public \DateTimeImmutable $at, public array $changed, public array $steps, public string $changes, public int $consecutive = 0)
    {
    }

    public function passed(): bool
    {
        return [] === array_filter($this->steps, static fn (GateStep $step): bool => GateStatus::Failed === $step->status());
    }

    /**
     * A fingerprint of what fails: the same failures give the same one, whatever the timings.
     */
    public function failure(): string
    {
        return hash('sha256', implode("\n", array_merge(...array_map(static fn (GateStep $step): array => [$step->name, ...$step->failures], $this->steps))));
    }
}
