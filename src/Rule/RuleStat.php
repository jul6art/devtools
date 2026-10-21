<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * What one rule did: how many times it was given to Claude, how many times it stopped a change (ADR-0027).
 */
final readonly class RuleStat
{
    public function __construct(
        public string $id,
        public int $applied = 0,
        public int $blocked = 0,
        public ?\DateTimeImmutable $lastApplied = null,
        public ?\DateTimeImmutable $lastBlocked = null,
    ) {
    }

    public function applied(\DateTimeImmutable $at): self
    {
        return new self($this->id, $this->applied + 1, $this->blocked, $at, $this->lastBlocked);
    }

    public function blocked(\DateTimeImmutable $at): self
    {
        return new self($this->id, $this->applied, $this->blocked + 1, $this->lastApplied, $at);
    }
}
