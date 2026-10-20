<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

/**
 * One line of `library.xml`: where a knowledge file came from, and when (ADR-0041) — the original sheet,
 * or one of its revisions (ADR-0044).
 */
final readonly class LibraryEntry
{
    /**
     * @param int $revision 1 for the sheet as first deposited, then one more per amendment
     */
    public function __construct(
        public string $key,
        public string $project,
        public \DateTimeImmutable $at,
        public string $tool,
        public string $sha256,
        public int $revision = 1,
    ) {
    }

    /**
     * `symfony-8` for the original, `symfony-8.r2` for its first amendment: the file name without `.md`,
     * and the key of the entry in the index.
     */
    public function name(): string
    {
        return 1 === $this->revision ? $this->key : $this->key.'.r'.$this->revision;
    }
}
