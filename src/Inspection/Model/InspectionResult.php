<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * Everything one inspection found: the workflows of a stack, and the source files none of them
 * references — the "Non couvert" section that makes visible what the analysis did not understand.
 */
final readonly class InspectionResult
{
    public string $stack;

    /**
     * @var list<Workflow>
     */
    public array $workflows;

    /**
     * @var list<FileRef>
     */
    public array $uncovered;

    /**
     * @param list<Workflow> $workflows
     * @param list<FileRef>  $uncovered
     */
    public function __construct(string $stack, array $workflows, array $uncovered = [])
    {
        $this->stack = NonEmpty::string($stack, 'stack');
        $this->workflows = SortedList::of($workflows, static fn (Workflow $workflow): string => $workflow->id->value, 'workflow');
        $this->uncovered = SortedList::of($uncovered, static fn (FileRef $file): string => $file->path, 'uncovered file');
    }
}
