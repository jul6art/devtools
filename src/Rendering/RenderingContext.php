<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\TrackingDocument;

/**
 * The other workflows of the scan, so that a page can link to them: identifiers to their type, route names
 * to the workflow they belong to.
 */
final readonly class RenderingContext
{
    /**
     * @param array<string, WorkflowType> $types       identifier => type
     * @param array<string, WorkflowId>   $entryPoints entry point name => identifier
     */
    private function __construct(private array $types, private array $entryPoints)
    {
    }

    /**
     * @param list<Workflow> $workflows
     */
    public static function of(array $workflows): self
    {
        $types = [];
        $entryPoints = [];

        foreach ($workflows as $workflow) {
            $types[$workflow->id->value] = $workflow->type;

            foreach ([$workflow->main, ...$workflow->satellites] as $entryPoint) {
                $entryPoints[$entryPoint->name] ??= $workflow->id;
            }
        }

        return new self($types, $entryPoints);
    }

    /**
     * The same context, built from tracking files — for `workflows:apply`, which does not inspect.
     *
     * @param list<TrackingDocument> $documents
     */
    public static function fromTracking(array $documents): self
    {
        $types = [];
        $entryPoints = [];

        foreach ($documents as $document) {
            $types[$document->id->value] = $document->type;

            foreach ([$document->main, ...$document->satellites] as $entryPoint) {
                $entryPoints[$entryPoint->name] ??= $document->id;
            }
        }

        return new self($types, $entryPoints);
    }

    public function workflowOf(string $entryPointName): ?WorkflowId
    {
        return $this->entryPoints[$entryPointName] ?? null;
    }

    /**
     * The page of `$target` relative to a page of type `$from`, or null when the workflow is not documented.
     */
    public function relativePage(WorkflowType $from, WorkflowId $target): ?string
    {
        $type = $this->types[$target->value] ?? null;

        if (!$type instanceof WorkflowType) {
            return null;
        }

        return $type->name === $from->name ? $target->pageName().'.md' : '../'.DevToolsDirectory::pageInDocs($type, $target);
    }
}
