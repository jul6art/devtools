<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * A tool of the project — a PHPStan rule, ESLint, a linter — called, and what it reports read (ADR-0027). DevTools
 * writes no analyser: the rule lives in the project's own tooling.
 */
final readonly class ToolCheck
{
    /**
     * The files of the change it watches, where the command says `{files}`.
     */
    public const string FILES = '{files}';

    /**
     * @param string|null $identifier only what the tool reports under it: a PHPStan identifier, an ESLint rule
     */
    public function __construct(public string $command, public ToolFormat $format, public ?string $identifier = null)
    {
    }
}
