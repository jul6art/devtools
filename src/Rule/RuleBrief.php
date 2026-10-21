<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * What Claude needs to turn feedback into a rule (ADR-0026): `.devtools/pending/rule.<feedback-id>.brief.xml`.
 */
final readonly class RuleBrief
{
    /**
     * @param string                                                                                                                                                    $date          the day the rule is created on, `Y-m-d`
     * @param non-empty-list<array{id: string, path: string, category: string, occurrences: int, summary: string, workflow: ?string, selector: ?string, file: ?string}> $feedback
     * @param list<string>                                                                                                                                              $files         the files the feedback is about, those of its workflows included
     * @param list<string>                                                                                                                                              $workflowTypes what `applies-to` may name
     * @param list<string>                                                                                                                                              $categories
     * @param list<array{id: string, path: string, status: string}>                                                                                                     $rules         the rules the project has
     */
    public function __construct(
        public string $date,
        public string $promptVersion,
        public string $promptPath,
        public string $vocabularyPath,
        public array $feedback,
        public array $files,
        public array $workflowTypes,
        public array $categories,
        public array $rules,
        public string $draftPath,
    ) {
    }

    /**
     * Named after the first feedback it promotes.
     */
    public static function fileName(string $feedback, string $kind): string
    {
        return \sprintf('rule.%s.%s', $feedback, $kind);
    }

    /**
     * @return non-empty-list<string>
     */
    public function feedbackIds(): array
    {
        return array_map(static fn (array $feedback): string => $feedback['id'], $this->feedback);
    }
}
