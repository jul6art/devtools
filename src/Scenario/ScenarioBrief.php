<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;

/**
 * What Claude needs to write the scenarios of one workflow (ADR-0020), in
 * `.devtools/pending/scenario.<id>.brief.xml`.
 *
 * Paths are relative to the project root, except the prompt's and the vocabulary's, which live in the
 * DevTools package.
 */
final readonly class ScenarioBrief
{
    /**
     * @param \DateTimeImmutable                                         $revision  the revision of the workflow the scenarios are written against
     * @param list<array{name: string, path: ?string, methods: ?string}> $routes    the workflow's own routes
     * @param list<array{path: string, origin: string}>                  $scenarios the scenarios that exist
     */
    public function __construct(
        public WorkflowId $workflow,
        public \DateTimeImmutable $revision,
        public string $promptVersion,
        public string $promptPath,
        public string $reason,
        public string $pagePath,
        public string $trackingPath,
        public ?string $knowledgePath,
        public string $vocabularyPath,
        public array $routes,
        public ?string $login,
        public array $scenarios,
        public string $draftPath,
    ) {
    }

    public static function fileName(WorkflowId $workflow, string $kind): string
    {
        return \sprintf('scenario.%s.%s', $workflow->value, $kind);
    }
}
