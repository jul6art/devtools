<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * One step of the gate as it ran: its status, and what to do about it — one actionable line per item.
 */
final readonly class GateStep
{
    /**
     * @param list<string> $failures what fails
     * @param list<string> $warnings what does not fail, unless --strict
     * @param list<string> $notes    what the step did, for the record
     * @param list<string> $blocked  the rules whose assertion failed (ADR-0027), for the journal (ADR-0029)
     */
    public function __construct(public string $name, public array $failures = [], public array $warnings = [], public array $notes = [], public int $milliseconds = 0, public array $blocked = [])
    {
    }

    public function status(): GateStatus
    {
        return match (true) {
            [] !== $this->failures => GateStatus::Failed,
            [] !== $this->warnings => GateStatus::Warning,
            default => GateStatus::Passed,
        };
    }

    public function timed(int $milliseconds): self
    {
        return new self($this->name, $this->failures, $this->warnings, $this->notes, $milliseconds, $this->blocked);
    }

    /**
     * Its warnings as failures: what `--strict` asks for.
     */
    public function strict(): self
    {
        return new self($this->name, [...$this->failures, ...$this->warnings], [], $this->notes, $this->milliseconds, $this->blocked);
    }
}
