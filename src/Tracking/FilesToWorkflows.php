<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\Mechanism;

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
     * Every file a workflow traverses, every test of it — and, as dependencies, the files of the mechanisms
     * it carries and of the workflows it depends on (ADR-0016). A file already linked to a workflow is not
     * linked to it a second time.
     *
     * @param list<TrackingDocument> $documents
     */
    public static function fromTracking(array $documents): self
    {
        $links = [];
        $byId = [];

        foreach ($documents as $document) {
            $byId[$document->id->value] = $document;

            foreach ($document->files as $file) {
                $links[$file->file->path][$document->id->value] = new FileLink($document->id, FileRelation::File);
            }

            foreach ($document->tests as $test) {
                $links[$test->path][$document->id->value] ??= new FileLink($document->id, FileRelation::Test);
            }
        }

        foreach ($documents as $document) {
            $dependencies = array_map(static fn (Mechanism $mechanism): string => $mechanism->declaredIn->path, $document->mechanisms);

            foreach ($document->dependsOn as $dependency) {
                foreach ($byId[$dependency->value]->files ?? [] as $file) {
                    $dependencies[] = $file->file->path;
                }
            }

            foreach ($dependencies as $path) {
                $links[$path][$document->id->value] ??= new FileLink($document->id, FileRelation::Dependency);
            }
        }

        return new self(array_map(array_values(...), $links));
    }

    /**
     * @return list<FileLink>
     */
    public function linksOf(string $path): array
    {
        return $this->links[$path] ?? [];
    }
}
