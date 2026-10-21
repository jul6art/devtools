<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

/**
 * What `--link` did in one project (ADR-0038): per neighbour, the calls linked and the orphans.
 */
final class LinkReport
{
    /**
     * @var array<string, array{calls: int, orphans: int}> neighbour => what was found
     */
    public array $projects = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    public int $pagesWritten = 0;

    public function __construct(public readonly string $project)
    {
    }
}
