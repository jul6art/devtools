<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

/**
 * The inverse index file → workflows (`graph/files-to-workflows.xml`), which impact analysis reads
 * instead of re-scanning (ADR-0016).
 */
final readonly class FilesToWorkflows
{
    /**
     * @var array<string, list<FileLink>> path => links, sorted by path then by identifier
     */
    public array $links;

    /**
     * @param array<string, list<FileLink>> $links
     */
    public function __construct(array $links)
    {
        foreach ($links as $path => $fileLinks) {
            usort($fileLinks, static fn (FileLink $a, FileLink $b): int => [$a->workflow->value, $a->relation->value] <=> [$b->workflow->value, $b->relation->value]);
            $links[$path] = $fileLinks;
        }

        ksort($links, \SORT_STRING);
        $this->links = $links;
    }

    /**
     * @param list<TrackingDocument> $documents
     */
    public static function fromTracking(array $documents): self
    {
        $links = [];

        foreach ($documents as $document) {
            foreach ($document->files as $file) {
                $links[$file->file->path][] = new FileLink($document->id, FileRelation::File);
            }

            foreach ($document->tests as $test) {
                $links[$test->path][] = new FileLink($document->id, FileRelation::Test);
            }
        }

        return new self($links);
    }

    /**
     * @return list<FileLink>
     */
    public function linksOf(string $path): array
    {
        return $this->links[$path] ?? [];
    }
}
