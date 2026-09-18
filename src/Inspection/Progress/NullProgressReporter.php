<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Progress;

/**
 * Nobody is watching: the pipeline's default, so that no test has to wire an output (ADR-0042).
 */
final class NullProgressReporter implements ProgressReporter
{
    #[\Override]
    public function stage(string $name, ?int $steps = null): void
    {
    }

    #[\Override]
    public function advance(string $label): void
    {
    }

    #[\Override]
    public function finish(): void
    {
    }
}
