<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Clock;

final readonly class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
