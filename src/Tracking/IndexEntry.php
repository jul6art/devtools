<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

final readonly class IndexEntry
{
    public function __construct(
        public WorkflowId $id,
        public WorkflowType $type,
        public string $title,
        public TrackingStatus $status,
        public Confidence $confidence,
        public GenerationMode $mode,
        public \DateTimeImmutable $updated,
        public ?string $group = null,
    ) {
    }

    public static function fromTracking(TrackingDocument $document): self
    {
        return new self($document->id, $document->type, $document->title, $document->status, $document->confidence, $document->generated->mode, $document->lastRevision()->at, $document->group?->directory);
    }

    /**
     * The page, relative to the documentation directory.
     */
    public function page(): string
    {
        return DevToolsDirectory::pageInDocs($this->type, $this->id, $this->group);
    }
}
