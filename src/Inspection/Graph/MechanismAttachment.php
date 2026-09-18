<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * A mechanism and the workflows it runs inside (ADR-0043).
 *
 * The adapter knows what a listener listens to; only the builder knows which files each workflow
 * traverses. So the adapter says *which* workflows are concerned, and the builder does the attaching.
 */
final readonly class MechanismAttachment
{
    /**
     * @param string|null  $type         the workflow type it applies to, all of them when null
     * @param list<string> $files        paths a workflow must traverse for it to apply; empty means no such condition
     * @param bool         $anyEntity    true for a mechanism that applies to any workflow reaching an entity
     * @param string|null  $stateMachine the state machine whose transitions it answers
     */
    public function __construct(
        public Mechanism $mechanism,
        public ?string $type = null,
        public array $files = [],
        public bool $anyEntity = false,
        public ?string $stateMachine = null,
    ) {
    }

    /**
     * @param list<FileRef> $files the files the workflow traverses
     */
    public function applies(WorkflowType $type, array $files, ?StateMachine $states = null): bool
    {
        if (null !== $this->type && $this->type !== $type->name) {
            return false;
        }

        if (null !== $this->stateMachine) {
            return $this->stateMachine === $states?->name;
        }

        if ($this->anyEntity) {
            return [] !== array_filter($files, static fn (FileRef $file): bool => FileRole::Entity === $file->role);
        }

        if ([] === $this->files) {
            return true;
        }

        return [] !== array_intersect($this->files, array_map(static fn (FileRef $file): string => $file->path, $files));
    }
}
