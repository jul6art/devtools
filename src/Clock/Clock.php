<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Clock;

/**
 * The only source of "now" in the core, so that every date written into a tracking file can be fixed
 * by a test (ADR-0001).
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
