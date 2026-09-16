<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

/**
 * Where the project stands in its repository: the commit, the branch, and the project's directory inside
 * the repository — git reports paths from the repository root, DevTools from the project root.
 */
final readonly class GitState
{
    public function __construct(public string $commit, public ?string $branch, public string $prefix)
    {
    }
}
