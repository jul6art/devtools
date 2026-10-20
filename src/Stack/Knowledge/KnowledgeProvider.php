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
        $source = $this->source($directory, $key);

        if (null === $source) {
            return null;
        }

        // The project's own file is its own source: written to itself, nothing changes.
        if ($copy) {
            $this->writer->write($directory->root->absolute($relative), (string) file_get_contents($source));
        }

        return $relative;
    }

    /**
     * The file the knowledge would be read from, copying nothing: what a reader that never writes — the MCP
     * server (ADR-0018) — reads.
     *
     * @return string|null an absolute path, or null when no knowledge exists for this key
     */
    public function source(DevToolsDirectory $directory, string $key): ?string
    {
        $project = $directory->root->absolute(DevToolsDirectory::NAME.'/knowledge/'.$key.'.md');

        if (is_file($project)) {
            return $project;
        }

        if ('_canvas' === $key) {
            return null;
        }

        $embedded = Resources::path('knowledge/'.$key.'.md');

        return $this->library?->file($key) ?? (is_file($embedded) ? $embedded : null);
    }
}
