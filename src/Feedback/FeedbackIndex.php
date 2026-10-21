<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Inspection\Model\SortedList;

/**
 * `.devtools/feedback/index.xml` (ADR-0025), sorted by identifier.
 */
final readonly class FeedbackIndex
{
    /**
     * @var list<FeedbackIndexEntry>
     */
    public array $entries;

    /**
     * @param list<FeedbackIndexEntry> $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = SortedList::of($entries, static fn (FeedbackIndexEntry $entry): string => $entry->id, 'feedback');
    }

    public function entry(string $id): ?FeedbackIndexEntry
    {
        return array_find($this->entries, static fn (FeedbackIndexEntry $entry): bool => $entry->id === $id);
    }

    public function with(FeedbackIndexEntry $entry): self
    {
        return new self([...array_values(array_filter($this->entries, static fn (FeedbackIndexEntry $known): bool => $known->id !== $entry->id)), $entry]);
    }
}
