<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * Where an assertion reads the files it watches (ADR-0027): the project as it is, or as a commit left it.
 */
interface FileSource
{
    /**
     * @return list<string> relative to the project, sorted
     */
    public function files(): array;

    /**
     * Null when the file does not exist there.
     */
    public function read(string $path): ?string;

    /**
     * Whether it is the project as it is now: the DOM it captured and its tools describe nothing else.
     */
    public function isCurrent(): bool;
}
