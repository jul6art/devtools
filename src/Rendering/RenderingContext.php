<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
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
     * @param array<string, string>       $groups      identifier => the directory of its group (ADR-0045)
     */
    private function __construct(private array $types, private array $entryPoints, private array $groups = [])
    {
    }

    /**
     * @param list<Workflow> $workflows
     */
    public static function of(array $workflows): self
    {
        $types = [];
        $entryPoints = [];
        $groups = [];

        foreach ($workflows as $workflow) {
            $types[$workflow->id->value] = $workflow->type;

            if ($workflow->group instanceof WorkflowGroup) {
                $groups[$workflow->id->value] = $workflow->group->directory;
            }

            foreach ([$workflow->main, ...$workflow->satellites] as $entryPoint) {
                $entryPoints[$entryPoint->name] ??= $workflow->id;
            }
        }

        return new self($types, $entryPoints, $groups);
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
        $groups = [];

        foreach ($documents as $document) {
            $types[$document->id->value] = $document->type;

            if ($document->group instanceof WorkflowGroup) {
                $groups[$document->id->value] = $document->group->directory;
            }

            foreach ([$document->main, ...$document->satellites] as $entryPoint) {
                $entryPoints[$entryPoint->name] ??= $document->id;
            }
        }

        return new self($types, $entryPoints, $groups);
    }

    public function workflowOf(string $entryPointName): ?WorkflowId
    {
        return $this->entryPoints[$entryPointName] ?? null;
    }

    /**
     * The page of `$target` relative to a page of type `$from`, or null when the workflow is not documented.
     *
     * `$group` is the directory of the page the link is written in (ADR-0045): without it, a page of
     * `routes/item.qr/` linked to `item.show.md` beside itself, where nothing is.
     */
    public function relativePage(WorkflowType $from, ?string $group, WorkflowId $target): ?string
    {
        $type = $this->types[$target->value] ?? null;

        if (!$type instanceof WorkflowType) {
            return null;
        }

        return self::relative(
            $from->name.(null === $group ? '' : '/'.$group),
            DevToolsDirectory::pageInDocs($type, $target, $this->groups[$target->value] ?? null),
        );
    }

    /**
     * `routes/item.qr` and `routes/item/show.md` → `../item/show.md`: what a reader's browser follows from
     * the directory the page lives in.
     */
    private static function relative(string $fromDirectory, string $path): string
    {
        $from = explode('/', $fromDirectory);
        $to = explode('/', $path);

        while ([] !== $from && \count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', \count($from)).implode('/', $to);
    }
}
