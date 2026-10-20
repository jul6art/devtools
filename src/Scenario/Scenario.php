<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;

/**
 * A reproducible scenario of a workflow (ADR-0020): who is logged in, what data is loaded — both names the
 * project resolves, never a password nor a row — then the steps a runner replays.
 */
final readonly class Scenario
{
    /**
     * The name of a scenario, of a screenshot: also a file name.
     */
    public const string NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * `<workflow-id>/<name>`.
     */
    public string $id;

    /**
     * @param list<string> $fixtures
     * @param list<Step>   $steps
     */
    public function __construct(
        public WorkflowId $workflow,
        public string $name,
        public array $steps,
        public ?string $login = null,
        public array $fixtures = [],
    ) {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a scenario name: lowercase letters, digits and inner hyphens.', $name));
        }

        if ([] === $steps) {
            throw new \InvalidArgumentException(\sprintf('The scenario "%s/%s" has no step.', $workflow->value, $name));
        }

        $this->id = $workflow->value.'/'.$name;
    }

    /**
     * The routes it goes to or asserts, each once, in the order of the steps.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (Step $step): ?string => $step->route(), $this->steps), \is_string(...))));
    }
}
