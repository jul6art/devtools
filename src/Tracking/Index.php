<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\SortedList;

/**
 * `index.xml`: every documented workflow, the last scan and its commit (specs § 4.4).
 */
final readonly class Index
{
    /**
     * @var list<IndexEntry>
     */
    public array $entries;

    /**
     * @param list<IndexEntry> $entries
     */
    public function __construct(public \DateTimeImmutable $scannedAt, public VcsState $vcs, array $entries)
    {
        $this->entries = SortedList::of($entries, static fn (IndexEntry $entry): string => $entry->id->value, 'index entry');
    }

    /**
     * @param list<TrackingDocument> $documents
     */
    public static function fromTracking(\DateTimeImmutable $scannedAt, VcsState $vcs, array $documents): self
    {
        return new self($scannedAt, $vcs, array_map(IndexEntry::fromTracking(...), $documents));
    }
}
