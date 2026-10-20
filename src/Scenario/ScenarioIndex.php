<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\SortedList;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;

/**
 * `.devtools/scenarios/index.xml` (ADR-0020), sorted by identifier.
 */
final readonly class ScenarioIndex
{
    /**
     * @var list<ScenarioIndexEntry>
     */
    public array $entries;

    /**
     * @param list<ScenarioIndexEntry> $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = SortedList::of($entries, static fn (ScenarioIndexEntry $entry): string => $entry->id, 'scenario');
    }

    public function entry(string $id): ?ScenarioIndexEntry
    {
        return array_find($this->entries, static fn (ScenarioIndexEntry $entry): bool => $entry->id === $id);
    }

    /**
     * @return list<ScenarioIndexEntry>
     */
    public function of(WorkflowId $workflow): array
    {
        return array_values(array_filter($this->entries, static fn (ScenarioIndexEntry $entry): bool => $entry->workflow->equals($workflow)));
    }

    public function with(ScenarioIndexEntry $entry): self
    {
        return new self([...$this->without($entry->id)->entries, $entry]);
    }

    public function without(string $id): self
    {
        return new self(array_values(array_filter($this->entries, static fn (ScenarioIndexEntry $entry): bool => $entry->id !== $id)));
    }
}
