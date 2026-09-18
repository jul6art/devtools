<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * What Claude needs to write the summary of a group page (ADR-0045), in
 * `.devtools/pending/group.<type>.<directory>.brief.xml`.
 *
 * A group is not a workflow: it has no model of its own, no files, no history. Its brief therefore carries
 * the routes it lists — name, title, page — and nothing else: the writing is three to five sentences saying
 * what the resource is for, and the pages of the routes say the rest.
 */
final readonly class GroupBrief
{
    /**
     * @param list<array{route: string, title: string, page: string}> $routes the routes of the group, in the order the page lists them
     */
    public function __construct(
        public WorkflowType $type,
        public string $directory,
        public string $title,
        public string $promptVersion,
        public string $promptPath,
        public string $language,
        public string $pagePath,
        public array $routes,
        public string $draftPath,
    ) {
    }

    public static function fileName(WorkflowType $type, string $directory, string $kind): string
    {
        return \sprintf('group.%s.%s.%s', $type->name, $directory, $kind);
    }
}
