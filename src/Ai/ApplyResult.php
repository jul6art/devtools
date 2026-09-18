<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

final class ApplyResult
{
    /**
     * @var list<string> workflows whose draft was applied
     */
    public array $accepted = [];

    /**
     * @var array<string, list<string>> workflow => broken rules
     */
    public array $refused = [];

    /**
     * @var array<string, string> knowledge key => the library file it was deposited in (ADR-0041)
     */
    public array $deposited = [];

    /**
     * @var list<string> what went wrong without failing the command: a library nobody can write to
     */
    public array $warnings = [];
}
