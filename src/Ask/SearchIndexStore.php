<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\XmlIndexStore;

/**
 * `.devtools/search.idx` (ADR-0039): written by every inspection, and built again by a question whenever a
 * page changed since — `workflows:apply` writes pages without inspecting. Ignored by git: a cache, never
 * a record.
 */
final readonly class SearchIndexStore
{
    public const string FILE = 'search.idx';

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SearchIndexBuilder $builder = new SearchIndexBuilder())
    {
    }

    public function write(DevToolsDirectory $directory, WorkflowTypeRegistry $types): SearchIndex
    {
        $index = $this->builder->build($directory, $types);
        $this->writer->write($directory->path(self::FILE), $index->toJson());

        return $index;
    }

    /**
     * The index as the documentation is now: the one on disk when nothing changed since it was written, else
     * built again — and kept, unless the caller promised to write nothing (the MCP server's tools).
     */
    public function current(DevToolsDirectory $directory, WorkflowTypeRegistry $types, bool $save = true): SearchIndex
    {
        $file = $directory->path(self::FILE);
        $stored = is_file($file) ? SearchIndex::fromJson((string) file_get_contents($file)) : null;

        if ($stored instanceof SearchIndex && $stored->fingerprint === SearchIndexBuilder::fingerprint($directory, new XmlIndexStore($types)->read($directory->indexFile()))) {
            return $stored;
        }

        return $save ? $this->write($directory, $types) : $this->builder->build($directory, $types);
    }
}
