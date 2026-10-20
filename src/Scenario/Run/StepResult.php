<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

use Jul6Art\DevTools\Scenario\Visual\VisualResult;

/**
 * One step as it was replayed: its verdict, how long it took, and for a failure or a `screenshot` what the
 * browser showed — paths relative to the run's directory — and how a `screenshot` compares with its baseline
 * (ADR-0022).
 */
final readonly class StepResult
{
    public function __construct(
        public int $index,
        public string $action,
        public Verdict $verdict,
        public int $milliseconds = 0,
        public ?string $message = null,
        public ?string $url = null,
        public ?string $screenshot = null,
        public ?string $dom = null,
        public ?VisualResult $visual = null,
    ) {
    }
}
