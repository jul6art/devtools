<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter;

use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;

/**
 * What an adapter found in one stack.
 */
final readonly class AdapterResult
{
    /**
     * @param list<EntryPointCandidate> $candidates
     * @param list<string>              $templateDirectories relative to the stack root
     * @param list<string>              $warnings
     * @param string|null               $fallbackCause       why the adapter could not use its best source; shown at the top of the report
     * @param list<Workflow>|null       $workflows           workflows already complete — the Claude path — which skip the PHP graph
     * @param list<FileRef>             $uncovered           with $workflows: the source files none of them reaches
     * @param list<string>|null         $discovery           files Claude has to look at: [] for a whole discovery, null when none is needed
     */
    public function __construct(
        public array $candidates,
        public array $templateDirectories = [],
        public array $warnings = [],
        public ?string $fallbackCause = null,
        public ?array $workflows = null,
        public array $uncovered = [],
        public ?array $discovery = null,
    ) {
    }
}
