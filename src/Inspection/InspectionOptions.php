<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection;

final readonly class InspectionOptions
{
    /**
     * @param string|null  $only  a workflow type: only its pages and tracking files are written
     * @param list<string> $force identifiers rewritten whatever their freshness
     * @param string|null  $since compare with this commit, and hash every file
     * @param bool         $prune delete the page and tracking file of orphaned workflows
     * @param bool         $noAi  write no brief for Claude: factual pages only
     */
    public function __construct(
        public string $path,
        public ?string $only = null,
        public bool $dryRun = false,
        public array $force = [],
        public bool $forceAll = false,
        public ?string $since = null,
        public bool $prune = false,
        public bool $noAi = false,
    ) {
        // The value ends up in `git diff <since>..HEAD`: one starting with a dash would be read as an
        // option of git (`--output=…` writes a file), not as a commit.
        if (null !== $since && (str_starts_with($since, '-') || '' === trim($since))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a commit: --since takes a commit, a tag or a branch.', $since));
        }
    }
}
