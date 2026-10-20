<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

/**
 * What the comparison of one capture found: its verdict, the share of the pixels that differ, where the
 * image of the difference and the diff of the DOM were written — relative to the run — and in one line what
 * changed.
 */
final readonly class VisualResult
{
    public function __construct(
        public VisualVerdict $verdict,
        public ?float $difference = null,
        public ?string $diffImage = null,
        public ?string $domDiff = null,
        public ?string $summary = null,
    ) {
    }
}
