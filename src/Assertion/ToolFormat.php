<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * How a tool of the project says what it found (ADR-0027).
 */
enum ToolFormat: string
{
    /**
     * `phpstan analyse --error-format=json`.
     */
    case Phpstan = 'phpstan';

    /**
     * `eslint --format json`.
     */
    case Eslint = 'eslint';

    /**
     * Anything else: its exit code, 0 or not.
     */
    case ExitCode = 'exit-code';
}
