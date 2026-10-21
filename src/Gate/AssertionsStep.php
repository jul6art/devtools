<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * `assertions` (ADR-0023): the mechanical checks phase 2 derives from the rules (ADR-0027). None yet: the step
 * exists, and passes, so that the gate does not change when they come.
 */
final readonly class AssertionsStep implements GateStepInterface
{
    #[\Override]
    public function name(): string
    {
        return 'assertions';
    }

    #[\Override]
    public function run(GateContext $context): GateStep
    {
        return new GateStep($this->name(), notes: ['None before phase 2.']);
    }
}
