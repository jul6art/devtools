<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Inspection\Model\SortedList;

/**
 * Every rule of the project, sorted by identifier (ADR-0026).
 */
final readonly class RuleIndex
{
    /**
     * Past this, what is given to Claude becomes the wall of text rules are there to avoid.
     */
    public const int INJECTED = 5;

    /**
     * @var list<RuleIndexEntry>
     */
    public array $entries;

    /**
     * @param list<RuleIndexEntry> $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = SortedList::of($entries, static fn (RuleIndexEntry $entry): string => $entry->id, 'rule');
    }

    public function entry(string $id): ?RuleIndexEntry
    {
        return array_find($this->entries, static fn (RuleIndexEntry $entry): bool => $entry->id === $id);
    }

    public function with(RuleIndexEntry $entry): self
    {
        return new self([...array_values(array_filter($this->entries, static fn (RuleIndexEntry $known): bool => $known->id !== $entry->id)), $entry]);
    }

    /**
     * The active rules that apply to a file, the most specific first, at most {@see self::INJECTED}: a proposed
     * rule is never given to Claude, nor an archived one.
     *
     * @param list<string> $types the kinds of the workflows the file is part of
     *
     * @return list<RuleIndexEntry>
     */
    public function applicableTo(string $file, array $types, int $limit = self::INJECTED): array
    {
        $applicable = [];

        foreach ($this->entries as $entry) {
            $specificity = RuleStatus::Active === $entry->status ? $entry->appliesTo->specificity($file, $types) : null;

            if (null !== $specificity) {
                $applicable[] = [$specificity, $entry];
            }
        }

        // The most specific first; between two as specific, the order of the identifiers.
        usort($applicable, static fn (array $a, array $b): int => [$b[0], $a[1]->id] <=> [$a[0], $b[1]->id]);

        return \array_slice(array_column($applicable, 1), 0, $limit);
    }
}
