<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * What Claude needs to write the assertion that enforces a rule (ADR-0027): `.devtools/pending/assertion.<rule>.brief.xml`.
 */
final readonly class AssertionBrief
{
    /**
     * @param array{id: string, path: string, instruction: string, globs: list<string>, workflowTypes: list<string>}                                 $rule
     * @param non-empty-list<array{id: string, path: string, summary: string, commit: ?string, workflow: ?string, selector: ?string, file: ?string}> $feedback
     * @param list<string>                                                                                                                           $before   the commits where the assertion must fail
     * @param list<string>                                                                                                                           $files    files the rule applies to
     * @param list<array{scenario: string, name: string, dom: string}>                                                                               $captures the DOM of the last run
     * @param list<array{name: string, path: string}>                                                                                                $tools    the tools the project has installed
     */
    public function __construct(
        public string $promptVersion,
        public string $promptPath,
        public string $vocabularyPath,
        public array $rule,
        public array $feedback,
        public array $before,
        public array $files,
        public array $captures,
        public array $tools,
        public string $draftPath,
    ) {
    }

    public static function fileName(string $rule, string $kind): string
    {
        return \sprintf('assertion.%s.%s', $rule, $kind);
    }
}
