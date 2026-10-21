<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * One step of the gate (ADR-0023). Every step runs, whatever the one before found: Claude sees everything in one
 * pass. Phase 2 adds steps — the assertions of ADR-0027 — without changing the gate.
 */
interface GateStepInterface
{
    public function name(): string;

    public function run(GateContext $context): GateStep;
}
