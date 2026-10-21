<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * `.devtools/graph/links.xml` (ADR-0038): the calls between this project and each neighbour it declares.
 */
final readonly class Links
{
    /**
     * @param list<ProjectLinks> $projects sorted by name
     */
    public function __construct(public array $projects = [])
    {
    }

    /**
     * The calls a workflow of this project takes part in, with the neighbour on the other side.
     *
     * @return list<array{ProjectLinks, Call}>
     */
    public function of(string $workflow): array
    {
        $calls = [];

        foreach ($this->projects as $project) {
            foreach ($project->calls as $call) {
                if ($call->workflowOn($project->localSide()) === $workflow) {
                    $calls[] = [$project, $call];
                }
            }
        }

        return $calls;
    }

    /**
     * @return list<array{ProjectLinks, Call}>
     */
    public function orphans(): array
    {
        $orphans = [];

        foreach ($this->projects as $project) {
            foreach ($project->orphans() as $call) {
                $orphans[] = [$project, $call];
            }
        }

        return $orphans;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ProjectLinks $project): string => $project->name, $this->projects);
    }

    public function project(string $name): ?ProjectLinks
    {
        foreach ($this->projects as $project) {
            if ($project->name === $name) {
                return $project;
            }
        }

        return null;
    }
}
