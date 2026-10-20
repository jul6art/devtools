<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

/**
 * One scenario as it was replayed. A login or a fixture that fails is the scenario's own message: no step ran.
 */
final readonly class ScenarioResult
{
    public Verdict $verdict;

    /**
     * @param list<StepResult> $steps
     */
    public function __construct(public string $id, public array $steps, public int $milliseconds = 0, public ?string $message = null)
    {
        $this->verdict = null === $message ? Verdict::worst(array_map(static fn (StepResult $step): Verdict => $step->verdict, $steps)) : Verdict::Error;
    }

    /**
     * What went wrong, in one line: the scenario's message, or its first step that did not pass.
     */
    public function failure(): ?string
    {
        if (null !== $this->message) {
            return $this->message;
        }

        $step = array_find($this->steps, static fn (StepResult $step): bool => \in_array($step->verdict, [Verdict::Failed, Verdict::Error], true));

        return $step instanceof StepResult ? \sprintf('step %d (%s): %s', $step->index, $step->action, $step->message ?? $step->verdict->value) : null;
    }
}
