<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * Where an assertion failed: a file, a line when there is one, and what was found.
 */
final readonly class Violation
{
    public function __construct(public ?string $file, public ?int $line, public string $found)
    {
    }

    public function where(): string
    {
        return null === $this->file ? '' : $this->file.(null === $this->line ? '' : ':'.$this->line);
    }
}
