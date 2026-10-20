<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

/**
 * One step as it was replayed: its verdict, how long it took, and for a failure or a `screenshot` what the
 * browser showed — paths relative to the run's directory.
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
    ) {
    }
}
