<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Impact;

/**
 * What a change reaches (ADR-0016): the workflows, the files no workflow references, and the files the map
 * may not know as they are now.
 */
final readonly class Impact
{
    /**
     * @param list<ImpactedWorkflow> $workflows sorted by identifier
     * @param list<string>           $uncovered files no workflow references: new, or outside every workflow
     * @param list<string>           $stale     files changed after the last inspection: the map may predate them
     */
    public function __construct(public array $workflows, public array $uncovered, public array $stale)
    {
    }

    /**
     * The warning a reader sees first, or null when the map is as fresh as the files asked about.
     */
    public function warning(): ?string
    {
        return [] === $this->stale ? null : \sprintf('The map predates a change to %s: run workflows:inspect for an exact answer.', implode(', ', $this->stale));
    }

    /**
     * @return array{warnings: list<string>, workflows: list<array{id: string, type: string, title: string, relation: string, files: list<string>, page: string, status: string}>, uncovered: list<string>}
     */
    public function toArray(): array
    {
        return [
            'warnings' => null === $this->warning() ? [] : [$this->warning()],
            'workflows' => array_map(static fn (ImpactedWorkflow $workflow): array => $workflow->toArray(), $this->workflows),
            'uncovered' => $this->uncovered,
        ];
    }
}
