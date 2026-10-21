<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

/**
 * What `devtools ask` answers (ADR-0039): the text, the pages it was given as sources, and what a reader
 * must know before trusting it — a source that is stale, a workflow cited that was not among the sources.
 */
final readonly class Answer
{
    /**
     * @param list<SelectedPage>                                              $sources
     * @param list<string>                                                    $warnings
     * @param array{input: int, output: int, cacheRead: int, cacheWrite: int} $usage
     */
    public function __construct(
        public string $text,
        public array $sources,
        public array $warnings,
        public array $usage = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
    ) {
    }
}
