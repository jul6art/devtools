<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * What one assertion said: passed, failed where, or could not tell — no capture of its scenario, a tool whose output
 * cannot be read.
 */
final readonly class AssertionResult
{
    /**
     * @param list<Violation> $violations
     * @param list<string>    $files      the files it judged
     */
    public function __construct(public Assertion $assertion, public array $violations = [], public ?string $unverifiable = null, public array $files = [])
    {
    }

    public function failed(): bool
    {
        return [] !== $this->violations;
    }

    public function passed(): bool
    {
        return [] === $this->violations && null === $this->unverifiable;
    }
}
