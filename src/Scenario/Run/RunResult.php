<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

/**
 * One execution of `scenarios:run`: `.devtools/runs/<timestamp>/result.xml` and what it captured.
 */
final readonly class RunResult
{
    /**
     * @param list<ScenarioResult> $scenarios
     */
    public function __construct(public \DateTimeImmutable $startedAt, public string $baseUrl, public array $scenarios)
    {
    }

    public function passed(): bool
    {
        return [] === array_filter($this->scenarios, static fn (ScenarioResult $scenario): bool => Verdict::Passed !== $scenario->verdict);
    }

    public function count(Verdict $verdict): int
    {
        return \count(array_filter($this->scenarios, static fn (ScenarioResult $scenario): bool => $verdict === $scenario->verdict));
    }
}
