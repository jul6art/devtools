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
}
