<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Impact;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Tracking\TrackingStatus;

/**
 * One workflow a change reaches, through the strongest of its links, and the files that do.
 */
final readonly class ImpactedWorkflow
{
    /**
     * @param list<string> $files the changed files that reach it, sorted
     * @param string       $page  relative to the project
     */
    public function __construct(
        public WorkflowId $id,
        public WorkflowType $type,
        public string $title,
        public ImpactRelation $relation,
        public array $files,
        public string $page,
        public TrackingStatus $status,
    ) {
    }

    /**
     * @return array{id: string, type: string, title: string, relation: string, files: list<string>, page: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'type' => $this->type->name,
            'title' => $this->title,
            'relation' => $this->relation->value,
            'files' => $this->files,
            'page' => $this->page,
            'status' => $this->status->value,
        ];
    }
}
