<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * Where the knowledge of a stack comes from (ADR-0012, ADR-0041), in order: the project's own file —
 * versioned, editable, never overwritten — then the shared library every project on this machine feeds,
 * then the one DevTools ships. Whichever is found is copied into the project the first time it is used,
 * so the project commits what it actually used.
 */
final readonly class KnowledgeProvider
{
    public function __construct(
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private ?KnowledgeLibrary $library = null,
    ) {
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

        if ('_canvas' === $key) {
            return null;
        }

        $source = $this->library?->file($key);

        if (null === $source) {
            $embedded = Resources::path('knowledge/'.$key.'.md');
            $source = is_file($embedded) ? $embedded : null;
        }

        if (null === $source) {
            return null;
        }

        if ($copy) {
            $this->writer->write($directory->root->absolute($relative), (string) file_get_contents($source));
        }

        return $relative;
    }
}
