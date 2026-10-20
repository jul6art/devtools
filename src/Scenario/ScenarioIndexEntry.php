<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;

/**
 * One scenario of `scenarios/index.xml`: its origin, the hash of the file as last recorded, and the revision
 * of its workflow it was written or reviewed against.
 */
final readonly class ScenarioIndexEntry
{
    public WorkflowId $workflow;

    public string $name;

    public function __construct(public string $id, public ScenarioOrigin $origin, public string $sha256, public \DateTimeImmutable $revision)
    {
        [$workflow, $name] = explode('/', $id, 2) + [1 => ''];
        $this->workflow = new WorkflowId($workflow);

        if (1 !== preg_match(Scenario::NAME, $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a scenario identifier: <workflow-id>/<name>.', $id));
        }

        $this->name = $name;
    }

    /**
     * What DevTools may write again: its own file, or Claude's — never a file a human touched.
     */
    public function isOwned(): bool
    {
        return ScenarioOrigin::Human !== $this->origin;
    }
}
