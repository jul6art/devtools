<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

/**
 * One line of `library.xml`: where a knowledge file came from, and when (ADR-0041).
 */
final readonly class LibraryEntry
{
    public function __construct(
        public string $key,
        public string $project,
        public \DateTimeImmutable $at,
        public string $tool,
        public string $sha256,
    ) {
    }
}
