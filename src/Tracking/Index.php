<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\SortedList;

/**
 * `index.xml`: every documented workflow, the last scan and its commit (specs § 4.4), and the directory the
 * pages were written in — which is how `workflows:apply` finds them again without being told.
 */
final readonly class Index
{
    /**
     * @var list<IndexEntry>
     */
    public array $entries;

    /**
     * @var list<IndexGroup>
     */
    public array $groups;

    /**
     * @param list<IndexEntry> $entries
     * @param list<IndexGroup> $groups  the controllers the pages are laid out under (ADR-0045)
     */
    public function __construct(public \DateTimeImmutable $scannedAt, public VcsState $vcs, array $entries, public string $docs = DevToolsDirectory::DEFAULT_DOCS, array $groups = [])
    {
        $this->entries = SortedList::of($entries, static fn (IndexEntry $entry): string => $entry->id->value, 'index entry');
        $this->groups = SortedList::of($groups, static fn (IndexGroup $group): string => $group->key(), 'index group');
    }

    /**
     * The entries of one group, in the order the index keeps them.
     *
     * @return list<IndexEntry>
     */
    public function of(IndexGroup $group): array
    {
        return array_values(array_filter($this->entries, static fn (IndexEntry $entry): bool => $entry->group === $group->directory && $entry->type->name === $group->type->name));
    }

    /**
     * @param list<TrackingDocument> $documents
     */
    public static function fromTracking(\DateTimeImmutable $scannedAt, VcsState $vcs, array $documents, string $docs = DevToolsDirectory::DEFAULT_DOCS): self
    {
        $groups = [];

        foreach ($documents as $document) {
            if (null !== $document->group) {
                $group = new IndexGroup($document->type, $document->group->directory, $document->group->title);
                $groups[$group->key()] = $group;
            }
        }

        return new self($scannedAt, $vcs, array_map(IndexEntry::fromTracking(...), $documents), $docs, array_values($groups));
    }
}
