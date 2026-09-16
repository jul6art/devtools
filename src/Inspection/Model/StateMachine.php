<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * A state machine a workflow drives, rendered as a `stateDiagram` (ADR-0008).
 *
 * Places keep their declared order: the first one is the initial place, and sorting them would
 * change the diagram's meaning.
 */
final readonly class StateMachine
{
    public string $name;

    /**
     * @param list<string>     $places
     * @param list<Transition> $transitions
     */
    public function __construct(string $name, public array $places, public array $transitions)
    {
        $this->name = NonEmpty::string($name, 'state machine name');

        if (\count($places) !== \count(array_unique($places))) {
            throw new InvalidModel(\sprintf('The state machine "%s" declares a place twice.', $this->name));
        }

        foreach ($transitions as $transition) {
            foreach ([...$transition->from, ...$transition->to] as $place) {
                if (!\in_array($place, $places, true)) {
                    throw new InvalidModel(\sprintf('The transition "%s" of the state machine "%s" references the unknown place "%s".', $transition->name, $this->name, $place));
                }
            }
        }
    }
}
