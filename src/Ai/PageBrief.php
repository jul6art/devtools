<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * What Claude needs to write one page (ADR-0011), in `.devtools/pending/page.<id>.brief.xml`.
 *
 * Paths are relative to the project root, except the prompt's, which lives in the DevTools package.
 */
final readonly class PageBrief
{
    /**
     * @param list<string>                              $reasons  why the page is being (re)written
     * @param bool                                      $amend    the inspection just added the revision this writing completes
     * @param list<string>                              $sections the closed list of sections the draft holds, in order
     * @param list<array{path: string, change: string}> $changes  the files added, removed or changed since the last revision
     * @param list<string>                              $facts    what changed in the workflow itself (ADR-0046), as one line each
     */
    public function __construct(
        public WorkflowId $workflow,
        public WorkflowType $type,
        public \DateTimeImmutable $revision,
        public bool $amend,
        public string $promptVersion,
        public string $promptPath,
        public string $language,
        public string $modelPath,
        public string $pagePath,
        public ?string $knowledgePath,
        public array $reasons,
        public array $sections,
        public array $changes,
        public string $draftPath,
        public array $facts = [],
    ) {
    }

    public static function fileName(WorkflowId $workflow, string $kind): string
    {
        return \sprintf('page.%s.%s', $workflow->value, $kind);
    }
}
