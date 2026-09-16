<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * Where the knowledge of a stack comes from (ADR-0012): the project's own file first — versioned, editable,
 * never overwritten — then the one DevTools ships, copied into the project the first time it is used.
 */
final readonly class KnowledgeProvider
{
    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    /**
     * @return string|null the knowledge file relative to the project root, or null when none exists yet
     */
    public function provide(DevToolsDirectory $directory, string $key, bool $copy = true): ?string
    {
        $relative = DevToolsDirectory::NAME.'/knowledge/'.$key.'.md';

        if (is_file($directory->root->absolute($relative))) {
            return $relative;
        }

        $embedded = Resources::path('knowledge/'.$key.'.md');

        if ('_canvas' === $key || !is_file($embedded)) {
            return null;
        }

        if ($copy) {
            $this->writer->write($directory->root->absolute($relative), (string) file_get_contents($embedded));
        }

        return $relative;
    }
}
